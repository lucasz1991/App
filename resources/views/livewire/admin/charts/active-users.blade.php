<div>
    <div id="{{ $chartId }}" style="height:{{ $height }}px; overflow-y:hidden;" class="scroll-container"></div>

    @script
        <script>
            (function () {
                const chartId = '{{ $chartId }}';

                // Überprüfen, ob ein Chart mit dieser ID existiert und entfernen
                if (ApexCharts.instances && ApexCharts.instances[chartId]) {
                    ApexCharts.instances[chartId].destroy();
                }

                const options = {
                    chart: {
                        id: chartId,
                        type: 'area',
                        height: {{ $height }},
                        animations: {
                            enabled: true,
                            easing: 'linear',
                            dynamicAnimation: {
                                speed: 1000
                            }
                        },
                        toolbar: { show: false },
                        zoom: { enabled: false }
                    },
                     dataLabels: {
                     enabled: false
                    },
                    stroke: { curve: 'smooth', width: 2 },
                    series: [{
                        name: 'Aktive Nutzer',
                        data: @json($data)
                    }],
                    xaxis: {
                        type: 'datetime',
                        categories: @json($timestamps),
                        labels: {
                            formatter: function (value) {
                                return new Date(value).toLocaleTimeString();
                            }
                        }
                    },
                    tooltip: {
                        x: {
                            format: 'HH:mm:ss'
                        }
                    }
                };

                const chart = new ApexCharts(document.querySelector(`#${chartId}`), options);
                chart.render();

                // Chart in ApexCharts.instances speichern
                if (!ApexCharts.instances) {
                    ApexCharts.instances = {};
                }
                ApexCharts.instances[chartId] = chart;
            })();
        </script>
    @endscript
</div>
