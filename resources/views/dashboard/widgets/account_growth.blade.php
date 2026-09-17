<x-ui.dashboard.trend-chart
    title="Kontenentwicklung"
    description="Letzte 14 Tage"
    :labels="$data['labels']"
    :values="$data['values']"
    type="line"
    tone="brand"
    icon="trending-up"
    :summary="$data['total']"
    summary-label="Konten gesamt"
    variant="minimal"
    class="!p-0 !border-0 !shadow-none !bg-transparent"
/>
