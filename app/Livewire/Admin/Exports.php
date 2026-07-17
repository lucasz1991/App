<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\ShelfRental;
use App\Models\ShelfRentalExtension;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Payout; 
use App\Models\Setting;

use Carbon\Carbon;

class Exports extends Component
{
    public $selectedMonthMonth;
    public $selectedMonthYear;
    public $autoExport;
    public $exportEmail;

    public function mount()
    {
        // Lade gespeicherte Einstellungen mit `pluck()->first()`, um Fehler zu vermeiden
        $this->autoExport = Setting::where('type', 'exports')->where('key', 'auto_export')->pluck('value')->first() ?? false;
        $this->exportEmail = Setting::where('type', 'exports')->where('key', 'export_email')->pluck('value')->first() ?? '';
    }
    
    public function saveExportSettings()
    {
        try {
            // Speichere die Checkbox (ensure boolean value)
            Setting::updateOrCreate(
                ['type' => 'exports', 'key' => 'auto_export'],
                ['value' => (bool) $this->autoExport]
            );
    
            // Speichere die E-Mail-Adresse
            Setting::updateOrCreate(
                ['type' => 'exports', 'key' => 'export_email'],
                ['value' => $this->exportEmail]
            );
    
            // Erfolgs-Message
            $this->dispatch('showAlert', 'Exporteinstellungen erfolgreich gespeichert.', 'success');
        } catch (\Exception $e) {
            // Fehler-Message
            $this->dispatch('showAlert', 'Fehler beim Speichern: ' . $e->getMessage(), 'error');
        }
    }
    

    public function exportBookings()
    {
        try {
            if (!$this->selectedMonthMonth || !$this->selectedMonthYear) {
                throw new \Exception('Bitte Monat und Jahr auswählen!');
            }
    
            $year = $this->selectedMonthYear;
            $month = $this->selectedMonthMonth;
    
            $shelfRentals = ShelfRental::whereYear('created_at', $year)
                                       ->whereMonth('created_at', $month)
                                       ->get();
    
            if ($shelfRentals->isEmpty()) {
                throw new \Exception('Keine Daten für den ausgewählten Monat gefunden.');
            }
    
            $csv = $this->generateCsv($shelfRentals, [
                'Regalbuchungsnummer', 'Kunde', 'Gesamtbetrag', 'Mietbeginn', 'Mietende', 'Rechnungsnummer', 'Erstellt am'
            ], function ($shelfRental) {
                return [
                    $this->sanitizeString($shelfRental->id),
                    $this->sanitizeString(optional($shelfRental->customer)->user->id . ' ' . optional($shelfRental->customer)->first_name . ' ' . optional($shelfRental->customer)->last_name),
                    str_replace('.', ',', sprintf('%.2f', (float)$shelfRental->total_price)),
                    $this->sanitizeString(Carbon::parse($shelfRental->rental_start)->format('d.m.Y')),
                    $this->sanitizeString(Carbon::parse($shelfRental->rental_end)->format('d.m.Y')),
                    $this->sanitizeString(optional($shelfRental->invoices->first())->id ?? 'Keine Rechnung'), // Falls keine Rechnung existiert
                    $this->sanitizeString(Carbon::parse($shelfRental->created_at)->format('d.m.Y')),
                ];
            });
            
    
            $filename = 'minifinds-regalbuchungen_export_' . $year . '-' . $month . '.csv';
    
            // **UTF-8 BOM für Excel**
            $csvUtf8 = "\xEF\xBB\xBF" . mb_convert_encoding($csv, 'UTF-8', 'auto');
    
            // **Base64 sicher kodieren**
            $encodedCsv = base64_encode($csvUtf8);
    
            $this->downloadCsv($csv, $filename, 'Die Regalbuchungen wurden erfolgreich exportiert.');
    
        } catch (\Exception $e) {
            // **Fehlermeldung senden**
            $this->dispatch('showAlert', 'Fehler: ' . $e->getMessage(), 'error');
        }
    }

    public function exportBookingExtends()
    {
        try {
            if (!$this->selectedMonthMonth || !$this->selectedMonthYear) {
                throw new \Exception('Bitte Monat und Jahr auswählen!');
            }
    
            $year = $this->selectedMonthYear;
            $month = $this->selectedMonthMonth;
    
            $extensions = ShelfRentalExtension::whereYear('created_at', $year)
                                              ->whereMonth('created_at', $month)
                                              ->where('is_admin', false)
                                              ->get();
    
            if ($extensions->isEmpty()) {
                throw new \Exception('Keine Verlängerungen für den ausgewählten Monat gefunden.');
            }
    
            $csv = $this->generateCsv($extensions, [
                'Verlaengerungs-Buchungs-ID', 'Regal-Buchungs-ID', 'Kunde', 'Altes Mietende', 'Neues Mietende', 'Bezahlt', 'Rechnungsnummer' , 'Verlängert am'
            ], function ($extension) {
                return [
                    $this->sanitizeString($extension->id), 
                    $this->sanitizeString($extension->shelf_rental_id), 
                    $this->sanitizeString(optional($extension->shelfRental->customer)->first_name . ' ' . optional($extension->shelfRental->customer)->last_name),
                    $this->sanitizeString(Carbon::parse($extension->previous_end_date)->format('d.m.Y')),
                    $this->sanitizeString(Carbon::parse($extension->new_end_date)->format('d.m.Y')),
                    number_format((float)$extension->amount_paid, 2, ',', '.'),
                    $this->sanitizeString($extension->invoice_id), 
                    $this->sanitizeString(Carbon::parse($extension->created_at)->format('d.m.Y H:i')),
                ];
            });
    
            $filename = 'minifinds-regalbuchungen_verlaengerungen_export_' . $year . '-' . $month . '.csv';
            $this->downloadCsv($csv, $filename, 'Die Verlängerungen wurden erfolgreich exportiert.');
        } catch (\Exception $e) {
            $this->dispatch('showAlert', 'Fehler: ' . $e->getMessage(), 'error');
        }
    }
    

    
    public function exportPayouts()
    {
        try {
            if (!$this->selectedMonthMonth || !$this->selectedMonthYear) {
                throw new \Exception('Bitte Monat und Jahr auswählen!');
            }

            $year = $this->selectedMonthYear;
            $month = $this->selectedMonthMonth;

            $payouts = Payout::whereYear('created_at', $year)
                         ->whereMonth('created_at', $month)
                         ->where('status', true)
                         ->get();

            if ($payouts->isEmpty()) {
                throw new \Exception('Keine Auszahlungen für den ausgewählten Monat gefunden.');
            }

            $csv = $this->generateCsv($payouts, [
                'Auszahlungs-ID', 'Kunde', 'Betrag', 'Angefordert am', 'Genehmigt am', 'Zahlungsmethode', 'Auszahlungsdetails'
            ], function ($payout) {
                // Kundeninformationen abrufen
                $customerInfo = optional($payout->customer)->user->id . ' ' . optional($payout->customer)->first_name . ' ' . optional($payout->customer)->last_name;
            
                // Auszahlungsbetrag und Datum formatieren
                $amount = number_format($payout->amount, 2, ',', '.');
                $requestedAt = Carbon::parse($payout->created_at)->format('d.m.Y H:i');
                $approvedAt = Carbon::parse($payout->updated_at)->format('d.m.Y H:i');
            
                // Zahlungsmethode ermitteln
                if (isset($payout->payout_details['paypal_email'])) {
                    $paymentMethod = 'PayPal';
                    $paymentDetails = 'PayPal: ' . $payout->payout_details['paypal_email'];
                } elseif (isset($payout->payout_details['iban'])) {
                    $paymentMethod = 'Bankueberweisung';
                    $paymentDetails = 'IBAN: ' . $payout->payout_details['iban'] . ', BIC: ' . $payout->payout_details['bic'];
                } else {
                    $paymentMethod = 'Unbekannt';
                    $paymentDetails = '❌ Keine Auszahlungsdetails verfügbar';
                }
            
                return [
                    $this->sanitizeString($payout->id),
                    $this->sanitizeString($customerInfo),
                    $this->sanitizeString($amount),
                    $this->sanitizeString($requestedAt),
                    $this->sanitizeString($approvedAt),
                    $this->sanitizeString($paymentMethod),
                    $paymentDetails,
                ];
            });

            $filename = 'minifinds-auszahlungen_export_' . $year . '-' . $month . '.csv';
            $this->downloadCsv($csv, $filename, 'Die Auszahlungen wurden erfolgreich exportiert.');
        } catch (\Exception $e) {
            $this->dispatch('showAlert', 'Fehler: ' . $e->getMessage(), 'error');
        }
    }

    public function exportCustomers()
    {
        try {
            if (!$this->selectedMonthMonth || !$this->selectedMonthYear) {
                throw new \Exception('Bitte Monat und Jahr auswählen!');
            }

            $year = $this->selectedMonthYear;
            $month = $this->selectedMonthMonth;

            $customers = Customer::whereYear('created_at', $year)
                                 ->whereMonth('created_at', $month)
                                 ->get();

            if ($customers->isEmpty()) {
                throw new \Exception('Keine neuen Kunden für den ausgewählten Monat gefunden.');
            }

            $csv = $this->generateCsv($customers, [
                'Kunden-ID', 'Name', 'E-Mail', 'Telefon', 'Straße', 'Stadt', 'Bundesland', 'PLZ', 'Land', 'Registrierungsdatum'
            ], function ($customer) {
                return [
                    $this->sanitizeString(optional($customer->user)->id), 
                    $this->sanitizeString($customer->first_name . ' ' . $customer->last_name),
                    optional($customer->user)->email, 
                    $this->sanitizeString($customer->phone_number),
                    $this->sanitizeString($customer->street),
                    $this->sanitizeString($customer->city),
                    $this->sanitizeString($customer->state),
                    $this->sanitizeString($customer->postal_code),
                    $this->sanitizeString($customer->country),
                    $this->sanitizeString(Carbon::parse($customer->created_at)->format('d.m.Y H:i')),
                ];
            });

            $filename = 'minifinds-kunden_export_' . $year . '-' . $month . '.csv';
            $this->downloadCsv($csv, $filename, 'Die Kunden wurden erfolgreich exportiert.');
        } catch (\Exception $e) {
            $this->dispatch('showAlert', 'Fehler: ' . $e->getMessage(), 'error');
        }
    }

    private function downloadCsv($csv, $filename, $successMessage)
    {
        $csvUtf8 = "\xEF\xBB\xBF" . mb_convert_encoding($csv, 'UTF-8', 'auto');
        $encodedCsv = base64_encode($csvUtf8);
        $this->dispatch('downloadCsv', [$encodedCsv, $filename]);
        $this->dispatch('showAlert', $successMessage, 'success');
    }
    
    /**
     * Entfernt unerwünschte Zeichen aus einem String.
     */
    private function sanitizeString($string)
    {
        if (!is_string($string)) {
            return $string;
        }
    
        // Zuerst Umlaute explizit ersetzen
        $umlautMapping = [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue',
        ];
        $string = strtr($string, $umlautMapping);
    
        // Alle übrigen diakritischen Zeichen (z.B. é, è, ê, etc.) transliterieren
        $string = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $string);
    
        // Entfernt nicht-druckbare Zeichen & Steuerzeichen
        $string = preg_replace('/[\x00-\x1F\x7F]/', '', $string);
    
        // Erlaubt Buchstaben, Zahlen, Satzzeichen & Leerzeichen
        $string = preg_replace('/[^A-Za-z0-9\s.,;:!?\-()€$]/', '', $string);
    
        return trim($string);
    }
    

    private function generateCsv($data, $headers, $callback)
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $headers, ';'); // **CSV mit Semikolon als Trennzeichen speichern**

        foreach ($data as $row) {
            fputcsv($handle, $callback($row), ';');
        }

        rewind($handle);
        $csvContent = stream_get_contents($handle);
        fclose($handle);

        return $csvContent;
    }





    public function render()
    {
        return view('livewire.admin.exports')->layout('layouts.master');
    }
}
