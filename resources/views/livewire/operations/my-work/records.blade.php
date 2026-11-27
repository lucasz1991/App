{{-- Mein Arbeitstag, Fokusansicht „Qualifikationen" (area=qualifications): Formular und Nachweisliste. Daten aus MyWork::render(). --}}
@include('livewire.operations.partials.personal-forms')
<x-tables.table class="rt-personal-record-list" :columns="[['label'=>'Nachweis','key'=>'record']]" :items="$qualifications" row-view="components.tables.rows.personal.qualification-row" empty="Keine Nachweise eingereicht. Ergänze hier deine gültigen Qualifikationen." /><x-tables.pagination :paginator="$qualifications" label="Nachweisseiten" />
