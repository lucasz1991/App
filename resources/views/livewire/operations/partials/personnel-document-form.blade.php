                @if($formKind === 'document_template')
                    <x-operations.field label="Vorlage" model="form.body" type="textarea" :wide="true" required placeholder="Dokumenttext"/>
                @elseif($formKind === 'signature_request')
                    <x-operations.field label="Dokumentstand" model="form.document_version_id" type="select" required><option value="">Auswählen</option>@foreach($versions as $version)<option value="{{ $version->id }}">{{ \App\Models\EmployeeDocumentRequirement::TYPES[$version->requirement->document_type] ?? $version->requirement->document_type }} · Version {{ $version->revision }}</option>@endforeach</x-operations.field><x-operations.field label="Frist" model="form.due_on" type="date" required/>
                @elseif($formKind === 'signature_result')
                    <div class="ops-full"><x-ui.forms.label for="personnel-signature-file" value="Unterzeichnetes Ergebnis (PDF)"/><x-ui.forms.input id="personnel-signature-file" type="file" wire:model="upload" accept="application/pdf" required/><x-ui.forms.input-error for="upload"/></div>
                @elseif($formKind === 'emergency_edit')
                    <x-operations.field label="Name" model="form.name" required/><x-operations.field label="Beziehung" model="form.relationship" required/><x-operations.field label="Telefon" model="form.phone" required/>
                    <label class="ops-full flex items-center gap-2"><x-ui.forms.checkbox wire:model="form.consent"/><span class="text-sm">Kontakt ist informiert.</span></label>
                @elseif($formKind === 'emergency_read')
                    @if($display === [])<x-operations.field label="Notfallgrund" model="form.note" type="textarea" required :wide="true"/>
                    @else<div class="ops-full space-y-2"><p class="font-semibold">{{ $display['name'] }}</p><p class="text-sm text-rt-muted">{{ $display['relationship'] }}</p><a class="text-sm font-medium" href="tel:{{ preg_replace('/[^+0-9]/','',$display['phone']) }}">{{ $display['phone'] }}</a></div>@endif
                @endif

