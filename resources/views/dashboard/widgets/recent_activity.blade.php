@forelse($data['entries'] as $entry)
    <div class="ops-row">
        <div style="display:flex;align-items:center;gap:10px;min-width:0;">
            <img src="{{ $entry['user']->profile_photo_url }}" alt="" style="width:28px;height:28px;border-radius:8px;object-fit:cover;flex-shrink:0;">
            <span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $entry['user']->name }}</span>
        </div>
        <span class="ops-muted" style="white-space:nowrap;">{{ $entry['lastSeen']->diffForHumans() }}</span>
    </div>
@empty
    <div class="ops-empty">Noch keine Aktivität.</div>
@endforelse
