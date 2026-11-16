{{--
    Ergebnis des Bremszettels in der Zeilenfolge des Vordrucks:
    Wagenzug · arbeitende Triebfahrzeuge · Gesamtzug. Alle Werte werden
    berechnet (brakeTotals/totals); eingegeben wird in wagon-brake-inputs.
--}}
@php
    $scope = $scope ?? 'desktop';
@endphp

<section class="rt-wagon-card" aria-labelledby="wagon-brake-result-title-{{ $scope }}">
    <header class="rt-wagon-card-head">
        <span class="rt-wagon-card-icon"><i class="far fa-clipboard-check" aria-hidden="true"></i></span>
        <h4 id="wagon-brake-result-title-{{ $scope }}">{{ __('app.brake_sheet') }}</h4>
        <span class="rt-wl-state" x-show="!totals.calculationValid && totals.wagons > 0" data-state="invalid">{{ __('app.wagon_calculation_blocked') }}</span>
    </header>
    <div class="rt-wagon-card-body">
        <table class="rt-wl-brake-table">
            <thead>
                <tr>
                    <th scope="col"><span class="sr-only">{{ __('app.row') }}</span></th>
                    <th scope="col"><span class="sr-only">{{ __('app.description') }}</span></th>
                    <th scope="col">{{ __('app.wagon_train') }}</th>
                    <th scope="col">{{ __('app.traction_short') }}</th>
                    <th scope="col">{{ __('app.whole_train') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>1</td>
                    <th scope="row">{{ __('app.weight_t') }}</th>
                    <td x-text="displayNumber(totals.weightRounded, 0, true)"></td>
                    <td x-text="displayNumber(brakeSheet.tractionWeight, 1)"></td>
                    <td x-text="displayNumber(brakeTotals.trainWeight, 1, true)"></td>
                </tr>
                <tr>
                    <td>2</td>
                    <th scope="row">{{ __('app.brake_weight_t') }}</th>
                    <td x-text="displayNumber(totals.usableBrakeWeight, 0)"></td>
                    <td x-text="displayNumber(brakeSheet.tractionBrakeWeight, 1)"></td>
                    <td x-text="displayNumber(brakeTotals.brakeWeight, 1)"></td>
                </tr>
                <tr>
                    <td>3</td>
                    <th scope="row">{{ __('app.axles') }}</th>
                    <td x-text="displayNumber(totals.axles, 0, true)"></td>
                    <td x-text="displayNumber(brakeSheet.tractionAxles, 0)"></td>
                    <td x-text="displayNumber(brakeTotals.axles, 0, true)"></td>
                </tr>
                <tr>
                    <td>4</td>
                    <th scope="row">{{ __('app.minimum_brake_percentage') }}</th>
                    <td></td>
                    <td></td>
                    <td x-text="displayNumber(brakeTotals.minimumPercentage, 0, true)"></td>
                </tr>
                <tr>
                    <td>5</td>
                    <th scope="row">{{ __('app.available_brake_percentage') }}</th>
                    <td></td>
                    <td></td>
                    <td x-text="displayNumber(brakeTotals.availablePercentage, 0)"></td>
                </tr>
                <tr :data-tone="brakeTotals.missingPercentage > 0 ? 'error' : (brakeTotals.missingPercentage === 0 ? 'ok' : '')">
                    <td>6</td>
                    <th scope="row">{{ __('app.missing_brake_percentage') }}</th>
                    <td></td>
                    <td></td>
                    <td x-text="displayNumber(brakeTotals.missingPercentage, 0)"></td>
                </tr>
                <tr>
                    <td>7</td>
                    <th scope="row">{{ __('app.last_vehicle_number') }}</th>
                    <td colspan="3" x-text="brakeTotals.lastVehicle || '—'"></td>
                </tr>
                <tr>
                    <td>8</td>
                    <th scope="row">{{ __('app.single_release_brakes') }}</th>
                    <td x-text="displayNumber(brakeSheet.singleReleaseBrakes, 0)"></td>
                    <td></td>
                    <td></td>
                </tr>
                <tr>
                    <td>9</td>
                    <th scope="row">{{ __('app.multi_release_brakes') }}</th>
                    <td x-text="displayNumber(brakeTotals.multiReleaseBrakes, 0, true)"></td>
                    <td></td>
                    <td></td>
                </tr>
                <tr>
                    <td>10</td>
                    <th scope="row">{{ __('app.disc_brakes_count') }}</th>
                    <td x-text="displayNumber(totals.discBrakes, 0, true)"></td>
                    <td></td>
                    <td></td>
                </tr>
                <tr>
                    <td>11</td>
                    <th scope="row">{{ __('app.plastic_brakes_count') }}</th>
                    <td x-text="displayNumber(totals.compositeBrakes, 0, true)"></td>
                    <td></td>
                    <td></td>
                </tr>
                <tr>
                    <td>12</td>
                    <th scope="row">{{ __('app.matrossow_brakes') }}</th>
                    <td x-text="displayNumber(brakeSheet.matrossowBrakes, 0)"></td>
                    <td></td>
                    <td></td>
                </tr>
                <tr>
                    <td>13</td>
                    <th scope="row">{{ __('app.train_length_m') }}</th>
                    <td x-text="displayNumber(totals.lengthRounded, 0, true)"></td>
                    <td x-text="displayNumber(brakeSheet.tractionLength, 1)"></td>
                    <td x-text="displayNumber(brakeTotals.trainLength, 1, true)"></td>
                </tr>
                <tr>
                    <td>14</td>
                    <th scope="row">{{ __('app.braked_axles') }}</th>
                    <td x-text="totals.wagons > 0 ? displayNumber(totals.brakedAxles, 0) : '—'"></td>
                    <td></td>
                    <td></td>
                </tr>
                <tr>
                    <td>15</td>
                    <th scope="row">{{ __('app.required_braked_axles') }}</th>
                    <td x-text="displayNumber(totals.requiredBrakedAxles, 0, true)"></td>
                    <td></td>
                    <td></td>
                </tr>
                <tr :data-tone="brakeTotals.slowerVehicles ? 'error' : ''">
                    <td>16</td>
                    <th scope="row">{{ __('app.slower_vehicles') }}</th>
                    <td colspan="3" x-text="brakeTotals.slowerVehicles === null ? '—' : (brakeTotals.slowerVehicles ? @js(__('app.yes')) + ', ' + brakeTotals.lowestSpeed + ' km/h' : @js(__('app.no')))"></td>
                </tr>
                <tr>
                    <td>31</td>
                    <th scope="row">{{ __('app.dangerous_goods') }}</th>
                    <td colspan="3" x-text="brakeTotals.dangerousGoods === '' ? '—' : (brakeTotals.dangerousGoods === 'yes' ? @js(__('app.yes')) : @js(__('app.no')))"></td>
                </tr>
            </tbody>
        </table>
    </div>
</section>
