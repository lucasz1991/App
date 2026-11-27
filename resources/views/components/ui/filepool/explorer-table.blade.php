@props(['sortBy' => 'name', 'sortDirection' => 'asc'])

<div class="rt-explorer-table-shell">
    <table {{ $attributes->class('rt-explorer-table') }} data-explorer-details>
        <thead>
            <tr>
                @foreach(['name', 'date', 'type', 'size'] as $column)
                    <th scope="col" class="rt-explorer-col-{{ $column }}"
                        @if($sortBy === $column) aria-sort="{{ $sortDirection === 'desc' ? 'descending' : 'ascending' }}" @endif>
                        <button type="button" wire:click="sortExplorer('{{ $column }}')" data-explorer-sort="{{ $column }}">
                            {{ __("file_explorer.columns.$column") }}
                            @if($sortBy === $column)
                                <i class="far {{ $sortDirection === 'desc' ? 'fa-arrow-down' : 'fa-arrow-up' }}" aria-hidden="true"></i>
                            @endif
                        </button>
                    </th>
                @endforeach
                <th scope="col" class="rt-explorer-col-actions"><span class="sr-only">{{ __('file_explorer.actions') }}</span></th>
            </tr>
        </thead>
        <tbody>{{ $slot }}</tbody>
    </table>
</div>
