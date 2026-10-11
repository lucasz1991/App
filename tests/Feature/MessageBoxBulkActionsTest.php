<?php

namespace Tests\Feature;

use App\Livewire\MessageBox;
use App\Livewire\Messages\MessageViewerModal;
use App\Models\File;
use App\Models\Message;
use App\Models\User;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File as Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Js;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class MessageBoxBulkActionsTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $recipient;

    private User $sender;

    private User $other;

    private string $compiledViews;

    private array $previousCompiledEnvironment;

    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->previousCompiledEnvironment = [getenv('VIEW_COMPILED_PATH'), $_ENV['VIEW_COMPILED_PATH'] ?? null, $_SERVER['VIEW_COMPILED_PATH'] ?? null];
        $this->compiledViews = sys_get_temp_dir().'/railtime-message-box-tests-'.bin2hex(random_bytes(8));
        mkdir($this->compiledViews, 0777, true);
        putenv('VIEW_COMPILED_PATH='.$this->compiledViews);
        $_ENV['VIEW_COMPILED_PATH'] = $_SERVER['VIEW_COMPILED_PATH'] = $this->compiledViews;
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        config(['app.locale' => 'de', 'app.timezone' => 'UTC']);
        $this->travelTo(CarbonImmutable::parse('2026-10-11T10:00:00Z'));
        Http::preventStrayRequests();
        $this->recipient = User::factory()->create(['role' => 'admin', 'name' => 'Inbox Recipient Fixture', 'email' => 'inbox-recipient@example.invalid']);
        $this->sender = User::factory()->create(['role' => 'staff', 'name' => 'Synthetic Sender', 'email' => 'inbox-sender@example.invalid']);
        $this->other = User::factory()->create(['role' => 'staff', 'name' => 'Other Inbox Fixture', 'email' => 'other-inbox@example.invalid']);

        // Real File deletion hooks retain their native Marketing source guard.
        // Only the columns used by that guard are needed in this isolated fixture.
        Schema::create('marketing_creatives', function (Blueprint $table): void {
            $table->id();
            $table->json('shared_content')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('marketing_creative_variants', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('marketing_creative_id');
            $table->text('html')->nullable();
            $table->text('css')->nullable();
            $table->json('builder_data')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    protected function tearDown(): void
    {
        try {
            Http::assertNothingSent();
        } finally {
            // Remove only this test's newly generated, explicit temporary directory.
            if (str_starts_with($this->compiledViews, sys_get_temp_dir().'/railtime-message-box-tests-')) {
                Filesystem::deleteDirectory($this->compiledViews);
            }
            parent::tearDown();
            putenv($this->previousCompiledEnvironment[0] === false ? 'VIEW_COMPILED_PATH' : 'VIEW_COMPILED_PATH='.$this->previousCompiledEnvironment[0]);
            foreach (['_ENV', '_SERVER'] as $index => $environment) {
                if ($this->previousCompiledEnvironment[$index + 1] === null) {
                    unset($GLOBALS[$environment]['VIEW_COMPILED_PATH']);
                } else {
                    $GLOBALS[$environment]['VIEW_COMPILED_PATH'] = $this->previousCompiledEnvironment[$index + 1];
                }
            }
        }
    }

    public function test_even_administrators_see_only_their_own_received_messages_and_exact_inbox_counts(): void
    {
        $unread = $this->message(['subject' => 'Own unread fixture']);
        $read = $this->message(['subject' => 'Own read fixture', 'status' => '2']);
        $foreign = $this->message(['to_user' => $this->other->id, 'subject' => 'Foreign private fixture']);
        $sent = $this->message(['from_user' => $this->recipient->id, 'to_user' => $this->other->id, 'subject' => 'Own sent fixture']);

        $this->inbox()->assertViewHas('totalCount', 2)->assertViewHas('unreadCount', 1)
            ->assertViewHas('messages', fn ($messages) => $messages->total() === 2 && $messages->pluck('id')->all() === [$read->id, $unread->id])
            ->assertSee('Own unread fixture')->assertSee('Own read fixture')
            ->assertDontSee('Foreign private fixture')->assertDontSee('Own sent fixture');
        $this->assertSame('1', $foreign->fresh()->status);
        $this->assertSame('1', $sent->fresh()->status);
    }

    public function test_numeric_pagination_keeps_fifteen_rows_and_an_exact_second_and_last_page(): void
    {
        $messages = $this->messages(31);
        $expected = array_reverse(array_map(fn (Message $message): int => $message->id, $messages));
        $component = $this->inbox()->assertViewHas('messages', fn ($page) => $page->currentPage() === 1 && $page->perPage() === 15 && $page->total() === 31 && $page->pluck('id')->all() === array_slice($expected, 0, 15));
        $component->call('gotoPage', 2)->assertViewHas('messages', fn ($page) => $page->currentPage() === 2 && $page->pluck('id')->all() === array_slice($expected, 15, 15));
        $component->call('nextPage')->assertViewHas('messages', fn ($page) => $page->currentPage() === 3 && $page->count() === 1 && $page->first()->id === $expected[30]);
        $component->call('previousPage')->assertViewHas('messages', fn ($page) => $page->currentPage() === 2 && $page->count() === 15);
    }

    #[DataProvider('searchFields')]
    public function test_encrypted_search_matches_sender_subject_and_plain_message_on_real_page_two(string $field): void
    {
        $needle = 'Prüfung-XYZ';
        if ($field === 'sender') {
            $this->sender->update(['name' => $needle]);
        }
        $matching = [];
        for ($index = 0; $index < 19; $index++) {
            $matching[] = $this->message([
                'subject' => $field === 'subject' ? 'Ticket '.$needle.' '.$index : 'Ticket '.$index,
                'message' => $field === 'message' ? '<p>Termin <strong>'.$needle.'</strong></p>' : 'Other body fixture',
            ]);
        }
        if ($field !== 'sender') {
            $this->message(['subject' => 'Unrelated inbox fixture']);
        }
        $this->message(['to_user' => $this->other->id, 'subject' => $needle, 'message' => $needle]);
        $raw = DB::table('messages')->where('id', $matching[0]->id)->first();
        $this->assertNotSame($matching[0]->subject, $raw->subject);
        $this->assertNotSame($matching[0]->message, $raw->message);

        $expected = array_reverse(array_map(fn (Message $message): int => $message->id, $matching));
        $this->inbox()->set('search', '  prüfung-xyz  ')
            ->assertViewHas('messages', fn ($page) => $page->total() === 19 && $page->count() === 15)
            ->call('gotoPage', 2)
            ->assertViewHas('messages', fn ($page) => $page->currentPage() === 2 && $page->total() === 19 && $page->count() === 4 && $page->pluck('id')->all() === array_slice($expected, 15));
    }

    public static function searchFields(): array
    {
        return ['sender' => ['sender'], 'encrypted subject' => ['subject'], 'encrypted plain body' => ['message']];
    }

    public function test_search_crosses_bounded_batches_and_clamps_to_the_true_last_matching_page(): void
    {
        $messages = $this->messages(121, ['subject' => 'Batch match', 'message' => '<p>Encoded &amp; match</p>']);
        $this->message(['subject' => 'Not matched', 'message' => 'Unrelated body']);
        $this->message(['to_user' => $this->other->id, 'subject' => 'Batch match', 'message' => 'Encoded & match']);
        $this->inbox()->set('search', 'encoded & match')->assertViewHas('messages', fn ($page) => $page->total() === 121 && $page->count() === 15)
            ->call('gotoPage', 999)->assertViewHas('messages', fn ($page) => $page->currentPage() === 9 && $page->total() === 121 && $page->count() === 1 && $page->first()->id === $messages[0]->id);
    }

    public function test_status_filters_do_not_replace_full_inbox_counts_or_leak_foreign_messages(): void
    {
        $unread = $this->message();
        $read = $this->message(['status' => '2']);
        $this->message(['to_user' => $this->other->id]);
        $this->inbox()->set('status', 'unread')->assertViewHas('totalCount', 2)->assertViewHas('unreadCount', 1)
            ->assertViewHas('messages', fn ($page) => $page->total() === 1 && $page->first()->id === $unread->id)
            ->set('status', 'read')->assertViewHas('totalCount', 2)->assertViewHas('unreadCount', 1)
            ->assertViewHas('messages', fn ($page) => $page->total() === 1 && $page->first()->id === $read->id);
    }

    public function test_page_selection_selects_only_visible_rows_and_is_reversible(): void
    {
        $messages = $this->messages(18);
        $firstPage = array_map(fn (Message $message): int => $message->id, array_slice($messages, 3));
        $component = $this->inbox()->call('togglePageSelection')->assertSet('selectedMessages', $firstPage)
            ->assertViewHas('selectedCount', 15)->assertViewHas('pageSelected', true);
        $component->call('togglePageSelection')->assertSet('selectedMessages', [])->assertViewHas('selectedCount', 0)
            ->call('gotoPage', 2)->call('togglePageSelection')->assertViewHas('selectedCount', 3)
            ->assertSet('selectedMessages', array_map(fn (Message $message): int => $message->id, array_slice($messages, 0, 3)))
            ->call('clearSelection')->assertSet('selectedMessages', []);
    }

    public function test_single_selection_rejects_foreign_and_other_page_ids(): void
    {
        $messages = $this->messages(16);
        $foreign = $this->message(['to_user' => $this->other->id]);
        $component = $this->inbox()->call('toggleMessageSelection', $messages[0]->id)->assertSet('selectedMessages', [])
            ->call('toggleMessageSelection', $foreign->id)->assertSet('selectedMessages', [])
            ->call('toggleMessageSelection', $messages[15]->id)->assertSet('selectedMessages', [$messages[15]->id]);
        $component->call('toggleMessageSelection', $messages[15]->id)->assertSet('selectedMessages', []);
    }

    public function test_search_status_and_page_changes_clear_selection_and_reset_filters(): void
    {
        $this->messages(18);
        $component = $this->inbox()->call('togglePageSelection')->call('gotoPage', 2)->assertSet('selectedMessages', [])
            ->call('togglePageSelection')->set('search', 'Fixture')->assertSet('selectedMessages', [])
            ->assertViewHas('messages', fn ($page) => $page->currentPage() === 1)
            ->call('togglePageSelection')->set('status', 'unread')->assertSet('selectedMessages', [])
            ->call('togglePageSelection')->call('resetFilters')->assertSet('search', '')->assertSet('status', 'all')->assertSet('selectedMessages', []);
        $component->assertViewHas('messages', fn ($page) => $page->currentPage() === 1);
    }

    public function test_single_read_and_unread_are_idempotent_and_never_touch_another_recipient(): void
    {
        $own = $this->message();
        $foreign = $this->message(['to_user' => $this->other->id]);
        $component = $this->inbox()->call('markAsRead', $own->id)->assertDispatched('inbox:refresh');
        $this->assertSame('2', $own->fresh()->status);
        $component->call('markAsRead', $own->id)->call('markAsRead', $foreign->id);
        $this->assertSame('1', $foreign->fresh()->status);
        $component->call('markAsUnread', $own->id)->assertDispatched('inbox:refresh');
        $this->assertSame('1', $own->fresh()->status);
        $foreign->update(['status' => '2']);
        $component->call('markAsUnread', $own->id)->call('markAsUnread', $foreign->id);
        $this->assertSame('2', $foreign->fresh()->status);
    }

    #[DataProvider('markActions')]
    public function test_forged_bulk_ids_only_update_valid_current_page_received_messages(string $action, string $initial, string $expected): void
    {
        $messages = $this->messages(16, ['status' => $initial]);
        $foreign = $this->message(['to_user' => $this->other->id, 'status' => $initial]);
        $sent = $this->message(['from_user' => $this->recipient->id, 'to_user' => $this->other->id, 'status' => $initial]);
        $valid = $messages[15];
        $this->inbox()->set('selectedMessages', [$valid->id, $foreign->id, $sent->id, $messages[0]->id, -1, 0, 'invalid', [$valid->id]])
            ->call($action)->assertDispatched('inbox:refresh')->assertSet('selectedMessages', []);
        $this->assertSame($expected, $valid->fresh()->status);
        foreach ([$foreign, $sent, $messages[0]] as $untouched) {
            $this->assertSame($initial, $untouched->fresh()->status);
        }
    }

    public static function markActions(): array
    {
        return ['mark selected read' => ['markSelectedAsRead', '1', '2'], 'mark selected unread' => ['markSelectedAsUnread', '2', '1']];
    }

    public function test_all_read_explicitly_affects_the_entire_own_inbox_not_just_search_or_current_page(): void
    {
        $own = $this->messages(20);
        $foreign = $this->message(['to_user' => $this->other->id]);
        $sent = $this->message(['from_user' => $this->recipient->id, 'to_user' => $this->other->id]);
        $this->inbox()->set('search', 'A term matching no fixture')->set('status', 'read')
            ->call('markAllAsRead')->assertDispatched('inbox:refresh')->assertViewHas('unreadCount', 0)->assertViewHas('totalCount', 20);
        foreach ($own as $message) {
            $this->assertSame('2', $message->fresh()->status);
        }
        $this->assertSame('1', $foreign->fresh()->status);
        $this->assertSame('1', $sent->fresh()->status);
    }

    public function test_bulk_action_revalidates_recipient_after_selection_was_made(): void
    {
        $message = $this->message();
        $component = $this->inbox()->call('toggleMessageSelection', $message->id);
        DB::table('messages')->where('id', $message->id)->update(['to_user' => $this->other->id]);
        $component->call('markSelectedAsRead')->assertSet('selectedMessages', []);
        $this->assertSame('1', $message->fresh()->status);
        $component->set('selectedMessages', [$message->id])->call('deleteSelectedMessages');
        $this->assertDatabaseHas('messages', ['id' => $message->id, 'to_user' => $this->other->id]);
    }

    public function test_bulk_deletion_uses_native_attachment_events_and_preserves_foreign_files(): void
    {
        Storage::fake('private');
        $own = $this->message();
        $foreign = $this->message(['to_user' => $this->other->id]);
        $sent = $this->message(['from_user' => $this->recipient->id, 'to_user' => $this->other->id]);
        $ownFile = $this->attachment($own, 'synthetic/owned.txt');
        $foreignFile = $this->attachment($foreign, 'synthetic/foreign.txt');
        $sentFile = $this->attachment($sent, 'synthetic/sent.txt');
        $this->inbox()->set('selectedMessages', [$own->id, $foreign->id, $sent->id])
            ->call('deleteSelectedMessages')->assertSet('selectedMessages', [])->assertDispatched('inbox:refresh');
        $this->assertDatabaseMissing('messages', ['id' => $own->id]);
        $this->assertDatabaseMissing('files', ['id' => $ownFile->id]);
        Storage::disk('private')->assertMissing('synthetic/owned.txt');
        foreach ([[$foreign, $foreignFile, 'synthetic/foreign.txt'], [$sent, $sentFile, 'synthetic/sent.txt']] as [$message, $file, $path]) {
            $this->assertDatabaseHas('messages', ['id' => $message->id]);
            $this->assertDatabaseHas('files', ['id' => $file->id]);
            Storage::disk('private')->assertExists($path);
        }
    }

    public function test_single_delete_clears_open_message_and_selection_and_clamps_empty_last_page(): void
    {
        Storage::fake('private');
        $messages = $this->messages(16);
        $last = $messages[0];
        $file = $this->attachment($last, 'synthetic/last-page.txt');
        $this->inbox()->call('gotoPage', 2)->call('toggleMessageSelection', $last->id)->set('openMessageId', $last->id)
            ->call('deleteMessage', $last->id)->assertSet('openMessageId', null)->assertSet('selectedMessages', [])
            ->assertDispatched('message-viewer:close')->assertDispatched('inbox:refresh')
            ->assertViewHas('messages', fn ($page) => $page->currentPage() === 1 && $page->total() === 15 && $page->count() === 15);
        $this->assertDatabaseMissing('files', ['id' => $file->id]);
        Storage::disk('private')->assertMissing('synthetic/last-page.txt');
    }

    public function test_selected_delete_cannot_target_a_hidden_page_by_forging_its_id(): void
    {
        $messages = $this->messages(16);
        $this->inbox()->set('selectedMessages', [$messages[0]->id])->call('deleteSelectedMessages');
        $this->assertDatabaseHas('messages', ['id' => $messages[0]->id]);
        $this->assertDatabaseCount('messages', 16);
    }

    public function test_failed_native_attachment_guard_rolls_back_the_whole_bulk_delete(): void
    {
        Storage::fake('private');
        $first = $this->message();
        $protected = $this->message();
        $firstFile = $this->attachment($first, 'synthetic/rollback-first.txt');
        $protectedFile = $this->attachment($protected, 'synthetic/protected.txt');
        // Represent an existing protected attachment without weakening or
        // mocking the actual File lifecycle authorization guard.
        DB::table('files')->where('id', $protectedFile->id)->update(['type' => 'employee-document']);

        $this->inbox()->set('selectedMessages', [$first->id, $protected->id])->call('deleteSelectedMessages')->assertForbidden();
        foreach ([$first, $protected] as $message) {
            $this->assertDatabaseHas('messages', ['id' => $message->id]);
        }
        foreach ([$firstFile, $protectedFile] as $file) {
            $this->assertDatabaseHas('files', ['id' => $file->id]);
        }
        Storage::disk('private')->assertExists('synthetic/rollback-first.txt');
        Storage::disk('private')->assertExists('synthetic/protected.txt');
    }

    public function test_bulk_selection_is_bounded_and_deduplicates_valid_positive_numeric_ids(): void
    {
        $messages = $this->messages(16);
        $visible = $messages[15];
        $this->inbox()->set('selectedMessages', [(string) $visible->id, $visible->id, true, 1.5, '1e2', '9999999999999999999999'])
            ->assertSet('selectedMessages', [$visible->id])->assertViewHas('selectedCount', 1)
            ->call('markSelectedAsRead')->assertSet('selectedMessages', []);
        $this->assertSame('2', $visible->fresh()->status);
        $this->assertSame(15, $this->recipient->receivedUnreadMessages()->count());

        $this->inbox()->set('selectedMessages', array_fill(0, 40, $messages[14]->id))->assertViewHas('selectedCount', 1)
            ->call('markSelectedAsRead');
        $this->assertSame(14, $this->recipient->receivedUnreadMessages()->count());
    }

    public function test_forged_ids_excluded_by_current_search_and_status_are_never_bulk_updated(): void
    {
        $visible = $this->message(['subject' => 'Visible match']);
        $hiddenSearch = $this->message(['subject' => 'Hidden search fixture']);
        $hiddenStatus = $this->message(['subject' => 'Visible match read', 'status' => '2']);
        $this->inbox()->set('search', 'Visible match')->set('status', 'unread')
            ->set('selectedMessages', [$visible->id, $hiddenSearch->id, $hiddenStatus->id])->call('markSelectedAsRead');
        $this->assertSame('2', $visible->fresh()->status);
        $this->assertSame('1', $hiddenSearch->fresh()->status);
        $this->assertSame('2', $hiddenStatus->fresh()->status);
    }

    public function test_single_foreign_delete_is_a_noop_including_its_attachments_and_open_url(): void
    {
        Storage::fake('private');
        $own = $this->message();
        $foreign = $this->message(['to_user' => $this->other->id]);
        $file = $this->attachment($foreign, 'synthetic/foreign-single.txt');
        $this->inbox()->set('openMessageId', $own->id)->call('deleteMessage', $foreign->id)
            ->assertSet('openMessageId', $own->id)->assertNotDispatched('message-viewer:close');
        $this->assertDatabaseHas('messages', ['id' => $foreign->id]);
        $this->assertDatabaseHas('files', ['id' => $file->id]);
        Storage::disk('private')->assertExists('synthetic/foreign-single.txt');
    }

    public function test_invalid_filter_and_out_of_range_page_are_normalized_without_empty_phantom_pages(): void
    {
        $this->messages(16);
        $this->inbox()->set('status', 'foreign')->assertSet('status', 'all')
            ->call('gotoPage', 999)->assertViewHas('messages', fn ($page) => $page->currentPage() === 2 && $page->count() === 1)
            ->call('gotoPage', -10)->assertViewHas('messages', fn ($page) => $page->currentPage() === 1 && $page->count() === 15)
            ->set('search', 'Nothing matches fixture')->assertViewHas('messages', fn ($page) => $page->currentPage() === 1 && $page->total() === 0);
    }

    public function test_open_detail_and_url_open_never_dispatch_foreign_message_ids(): void
    {
        $own = $this->message();
        $foreign = $this->message(['to_user' => $this->other->id]);
        $this->inbox()->call('openMessageDetail', $foreign->id)->assertNotDispatched('message-viewer:open');
        $this->inbox()->call('openMessageDetail', $own->id)->assertDispatched('message-viewer:open', messageId: $own->id);
        Livewire::actingAs($this->recipient)->withQueryParams(['open' => $foreign->id])->test(MessageBox::class)
            ->assertSet('openMessageId', null)->assertNotDispatched('message-viewer:open');
        Livewire::actingAs($this->recipient)->withQueryParams(['open' => $own->id])->test(MessageBox::class)
            ->assertSet('openMessageId', $own->id)->assertDispatched('message-viewer:open', messageId: $own->id);
    }

    public function test_existing_viewer_marks_only_owned_messages_read_and_ignores_foreign_open(): void
    {
        $own = $this->message();
        $foreign = $this->message(['to_user' => $this->other->id]);
        Livewire::actingAs($this->recipient)->test(MessageViewerModal::class)->call('open', $foreign->id)
            ->assertSet('isOpen', false)->assertSet('message', null)
            ->call('open', $own->id)->assertSet('isOpen', true)->assertDispatched('inbox:refresh')
            ->dispatch('message-viewer:close')->assertSet('isOpen', false)->assertSet('message', null);
        $this->assertSame('2', $own->fresh()->status);
        $this->assertSame('1', $foreign->fresh()->status);
    }

    public function test_unauthenticated_component_and_stale_authenticated_actions_fail_closed(): void
    {
        Livewire::test(MessageBox::class)->assertForbidden();
        $own = $this->message();
        $component = $this->inbox();
        Auth::logout();
        $component->call('markAsRead', $own->id)->assertForbidden();
        $this->assertSame('1', $own->fresh()->status);
    }

    public function test_native_inbox_table_preserves_mobile_column_headers_and_fixed_body_pager_contracts(): void
    {
        $this->messages(16);
        $xpath = $this->xpath($this->inbox()->html());
        $tables = $xpath->query('//*[@data-rt-premium-table]');
        $this->assertCount(1, $tables);
        $table = $tables->item(0);
        $this->assertSame('body', $table->getAttribute('data-table-scroll'));
        $this->assertTrue($table->hasAttribute('data-table-mobile-header'));
        $this->assertSame('message-inbox', $table->getAttribute('data-table-pagination-key'));
        $this->assertSame('true', $table->getAttribute('data-list-has-next'));
        $this->assertSame('false', $table->getAttribute('data-list-has-previous'));
        $this->assertCount(0, $xpath->query('.//*[contains(@class,"rt-table-mobile-sort")]', $table));
        $headers = $xpath->query('.//*[@role="columnheader"]', $table);
        $this->assertSame(['Von', 'Nachricht', 'Datum'], array_map(fn ($node) => trim($node->textContent), iterator_to_array($headers)));
        $this->assertStringContainsString('hidden md:block', $headers->item(2)->getAttribute('class'));
        $body = $xpath->query('.//*[@data-table-scroll-body]', $table);
        $this->assertCount(1, $body);
        $this->assertSame('0', $body->item(0)->getAttribute('tabindex'));
        $this->assertSame('Mein Posteingang – scrollbarer Inhalt', $body->item(0)->getAttribute('aria-label'));
        $pager = $xpath->query('//nav[@data-table-pagination]');
        $this->assertCount(1, $pager);
        $this->assertSame('Nachrichtenseiten', $pager->item(0)->getAttribute('aria-label'));
        $this->assertSame('message-inbox', $pager->item(0)->getAttribute('data-table-pagination-key'));
        $this->assertCount(0, $xpath->query('//*[@data-table-scroll-body]//nav[@data-table-pagination]'));
        $this->assertCount(15, $xpath->query('//*[@role="checkbox"]'));
        $this->assertCount(15, $xpath->query('//*[@data-table-row-interactive="true"]'));
    }

    public function test_header_uses_native_expandable_search_radio_filter_and_explicit_own_inbox_all_read_scope(): void
    {
        $this->message();
        $this->message(['status' => '2']);
        $component = $this->inbox();
        $xpath = $this->xpath($component->html());
        $this->assertCount(1, $xpath->query('//template[@*[name()="x-teleport" and .="[data-page-header-search]"]]'));
        $this->assertCount(1, $xpath->query('//template[@*[name()="x-teleport" and .="[data-page-header-metrics]"]]'));
        $search = $xpath->query('//input[@id="inbox-search"]');
        $this->assertCount(1, $search);
        $this->assertSame('Absender, Betreff oder Nachricht suchen', $search->item(0)->getAttribute('placeholder'));
        $this->assertSame('search', $search->item(0)->getAttribute('wire:model.live.debounce.400ms'));
        $this->assertCount(1, $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," rt-expandable-search ")]'));
        $filters = $xpath->query('//*[@role="menuitemradio"]');
        $this->assertCount(3, $filters);
        $this->assertSame(['true', 'false', 'false'], array_map(fn ($node) => $node->getAttribute('aria-checked'), iterator_to_array($filters)));
        $allRead = $xpath->query('//button[@*[name()="wire:click" and .="markAllAsRead"]]');
        $this->assertCount(1, $allRead);
        $this->assertSame('Alle 1 ungelesenen Nachrichten in meinem gesamten Posteingang als gelesen markieren', $allRead->item(0)->getAttribute('aria-label'));
        $this->assertFalse($allRead->item(0)->hasAttribute('disabled'));
        $this->assertCount(0, $xpath->query('//button[@aria-label="Zurück"]'));
        $this->assertStringContainsString(':title-in-topbar="true"', file_get_contents(resource_path('views/livewire/message-box.blade.php')));
        $component->call('markAllAsRead');
        $xpath = $this->xpath($component->html());
        $this->assertTrue($xpath->query('//button[@*[name()="wire:click" and .="markAllAsRead"]]')->item(0)->hasAttribute('disabled'));
    }

    public function test_bulk_and_single_delete_use_existing_confirmation_not_direct_destructive_wire_calls(): void
    {
        $message = $this->message();
        $component = $this->inbox()->call('toggleMessageSelection', $message->id);
        $xpath = $this->xpath($component->html());
        $this->assertCount(1, $xpath->query('//*[@data-tables-bulk-menu]'));
        foreach (['markSelectedAsRead', 'markSelectedAsUnread', 'clearSelection'] as $method) {
            $this->assertCount(1, $xpath->query('//a[@*[name()="wire:click.prevent" and .="'.$method.'"]]'));
        }
        $deletes = $xpath->query('//a[@data-rt-tone="danger"]');
        $this->assertCount(2, $deletes);
        foreach ($deletes as $delete) {
            $this->assertFalse($delete->hasAttribute('wire:click'));
            $this->assertFalse($delete->hasAttribute('wire:click.prevent'));
            $expression = $delete->getAttribute('x-on:click.prevent');
            $this->assertStringContainsString('$dispatch("rt-confirm",', $expression);
            $this->assertStringContainsString('confirmLabel: '.Js::from('Endgültig löschen')->toHtml(), $expression);
            $this->assertStringContainsString('nicht r\u00fcckg\u00e4ngig', $expression);
        }
        $this->assertStringContainsString('$wire.deleteMessage('.$message->id.')', $deletes->item(0)->getAttribute('x-on:click.prevent').$deletes->item(1)->getAttribute('x-on:click.prevent'));
        $this->assertStringContainsString('$wire.deleteSelectedMessages()', $deletes->item(0)->getAttribute('x-on:click.prevent').$deletes->item(1)->getAttribute('x-on:click.prevent'));
        $this->assertDatabaseHas('messages', ['id' => $message->id]);
    }

    public function test_message_rows_escape_untrusted_content_and_expose_read_selection_and_attachment_states(): void
    {
        Storage::fake('private');
        $this->sender->update(['name' => '<img src=x onerror=alert(1)> Sender']);
        $message = $this->message(['subject' => '<svg onload=alert(2)>Subject</svg>', 'message' => '<img src=x onerror=alert(3)><p>Readable &amp; safe</p>']);
        $this->attachment($message, 'synthetic/row.txt');
        $component = $this->inbox();
        $xpath = $this->xpath($component->html());
        $this->assertCount(0, $xpath->query('//script|//*[@onerror]|//*[@onload]'));
        $this->assertCount(1, $xpath->query('//*[@data-message-unread="true"]'));
        $checkbox = $xpath->query('//*[@role="checkbox"]')->item(0);
        $this->assertSame('false', $checkbox->getAttribute('aria-checked'));
        $this->assertStringContainsString($message->subject, $checkbox->getAttribute('aria-label'));
        $this->assertSame('Readable & safe', trim($xpath->query('//*[contains(@class,"rt-message-inbox__snippet")]')->item(0)->textContent));
        $this->assertSame('1 Anhang', trim($xpath->query('//*[contains(@class,"rt-message-inbox__attachment")]')->item(0)->textContent));
        $this->assertStringContainsString('&lt;svg onload=alert(2)&gt;Subject&lt;/svg&gt;', $component->html());
        foreach (['//*[@role="checkbox"]', '//button[contains(@class,"rt-message-inbox__subject")]', '//button[contains(@class,"rt-message-inbox__row-action")]'] as $selector) {
            $controls = $xpath->query($selector);
            $this->assertCount(1, $controls);
            $this->assertTrue($controls->item(0)->hasAttribute('x-on:keydown.enter.stop'), 'Nested native buttons must stop the row-level Enter handler without preventing their own click.');
            $this->assertFalse($controls->item(0)->hasAttribute('x-on:keydown.enter.prevent'));
            $this->assertFalse($controls->item(0)->hasAttribute('x-on:keydown.enter.stop.prevent'));
        }
        $component->call('toggleMessageSelection', $message->id);
        $xpath = $this->xpath($component->html());
        $this->assertSame('true', $xpath->query('//*[@role="checkbox"]')->item(0)->getAttribute('aria-checked'));
        $this->assertCount(1, $xpath->query('//*[@data-selected="true"]'));
        $component->call('markSelectedAsRead');
        $xpath = $this->xpath($component->html());
        $this->assertCount(1, $xpath->query('//*[@data-message-unread="false"]'));
        $this->assertSame('Gelesen', trim($xpath->query('//*[contains(@class,"rt-message-inbox__subject")]/*[contains(@class,"sr-only")]')->item(0)->textContent));
    }

    public function test_empty_states_distinguish_an_empty_inbox_search_and_read_filter_without_hiding_pagination(): void
    {
        $component = $this->inbox()->assertSee('Dein Posteingang ist leer. Neue Nachrichten erscheinen hier.');
        $component->set('status', 'unread')->assertSee('Alles gelesen – keine ungelesenen Nachrichten.')
            ->set('status', 'read')->assertSee('Noch keine gelesenen Nachrichten.')
            ->set('search', 'No matching fixture')->assertSee('Keine Nachrichten für diese Suche gefunden.');
        $xpath = $this->xpath($component->html());
        $this->assertCount(1, $xpath->query('//nav[@data-table-pagination]'));
        $this->assertCount(0, $xpath->query('//*[@role="checkbox"]'));
        $this->assertCount(3, $xpath->query('//*[@role="columnheader"]'));
    }

    private function inbox()
    {
        return Livewire::actingAs($this->recipient)->test(MessageBox::class)->assertSuccessful();
    }

    private function message(array $attributes = []): Message
    {
        $this->sequence++;

        $message = Message::create($attributes + [
            'from_user' => $this->sender->id,
            'to_user' => $this->recipient->id,
            'subject' => 'Fixture message '.$this->sequence,
            'message' => 'Synthetic inbox body '.$this->sequence,
            'status' => '1',
        ]);
        $timestamp = now()->addSeconds($this->sequence);
        $message->forceFill(['created_at' => $timestamp, 'updated_at' => $timestamp])->save();

        return $message;
    }

    /** @return list<Message> */
    private function messages(int $count, array $attributes = []): array
    {
        $messages = [];
        for ($index = 0; $index < $count; $index++) {
            $messages[] = $this->message($attributes);
        }

        return $messages;
    }

    private function attachment(Message $message, string $path): File
    {
        Storage::disk('private')->put($path, 'Synthetic attachment fixture');

        return $message->files()->create(['name' => basename($path), 'path' => $path, 'disk' => 'private', 'mime_type' => 'text/plain', 'type' => 'txt']);
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }
}
