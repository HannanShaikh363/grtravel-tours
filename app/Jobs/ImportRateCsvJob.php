<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Location;
use App\Models\Transport;
use App\Models\Rate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use ProtoneMedia\Splade\Facades\Toast;

class ImportRateCsvJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    protected $filePath;

    /**
     * Create a new job instance.
     */
    public function __construct($filePath)
    {
        $this->filePath = $filePath;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $file = $this->filePath;

        // Identify the file extension and choose the correct reader
        $extension = pathinfo($file, PATHINFO_EXTENSION);

        if ($extension === 'csv') {
            $reader = IOFactory::createReader('Csv');
        } else if ($extension === 'xlsx') {
            $reader = IOFactory::createReader('Xlsx');
        } else {
            // In case of unsupported format, return without doing anything
            return;
        }

        // Load the file into a Spreadsheet object
        $spreadsheet = $reader->load($file);

        // Get the first worksheet
        $worksheet = $spreadsheet->getActiveSheet();

        // Process rows in chunks
        $chunkSize = 100;
        $highestRow = $worksheet->getHighestRow(); // Total number of rows

        for ($startRow = 2; $startRow <= $highestRow; $startRow += $chunkSize) {
            $chunkData = [];

            for ($row = $startRow; $row < $startRow + $chunkSize && $row <= $highestRow; $row++) {
                // Using cell references instead of getCellByColumnAndRow
                $chunkData[] = [
                    'name'                   => $worksheet->getCell('A' . $row)->getValue(),
                    'from_location_id'       => $worksheet->getCell('B' . $row)->getValue(),
                    'to_location_id'         => $worksheet->getCell('C' . $row)->getValue(),
                    'vehicle_make'         => $worksheet->getCell('D' . $row)->getValue(),
                    'transport_id'           => $worksheet->getCell('E' . $row)->getValue(),
                    'vehicle_seating_capacity' => $worksheet->getCell('F' . $row)->getValue(),
                    'vehicle_luggage_capacity' => $worksheet->getCell('G' . $row)->getValue(),
                    'rate'                   => $worksheet->getCell('H' . $row)->getValue(),
                    'package'                => $worksheet->getCell('I' . $row)->getValue(),
                    'currency'               => $worksheet->getCell('J' . $row)->getValue(),
                    'effective_date'         => $worksheet->getCell('K' . $row)->getValue(),
                    'expiry_date'            => $worksheet->getCell('L' . $row)->getValue(),
                    'route_type'             => $worksheet->getCell('M' . $row)->getValue(),
                    'time_remarks'             => $worksheet->getCell('N' . $row)->getValue(),
                    'remarks'             => $worksheet->getCell('O' . $row)->getValue(),
                    'hours'             => $worksheet->getCell('P' . $row)->getValue(),
                    'rate_type'             => $worksheet->getCell('Q' . $row)->getValue(),
                ];
            }

            // Process the chunk of data
            $this->getchunkdata($chunkData);
        }

        Toast::title('CSV Imported successfully!')
            ->success()
            ->rightBottom()
            ->autoDismiss(5);
    }

    public function getchunkdata($chunkData)
    {
        // Define the required fields for validation
        $requiredFields = [
            'from_location_id',
            'to_location_id',
            'transport_id',
            'vehicle_seating_capacity',
            'vehicle_luggage_capacity',
            'rate',
            'package',
            'name',
            'currency',
            'effective_date',
            'expiry_date',
            'route_type',
            'vehicle_make',
            'time_remarks',
            'remarks',
            'hours',
            'rate_type',
        ];

        foreach ($chunkData as $column) {
            // Check for extra fields
            $extraFields = array_diff(array_keys($column), $requiredFields);
            if (!empty($extraFields)) {
                Toast::title('Invalid CSV Structure! Extra fields: ' . implode(', ', $extraFields))
                    ->danger()
                    ->rightBottom()
                    ->autoDismiss(5);
                continue; // Skip this row since it has extra fields
            }

            // Retrieve the name for from and to locations from the column data
            $fromLocationName = trim(strtolower($column['from_location_id']));
            $toLocationName = trim(strtolower($column['to_location_id']));
            $transportName = trim(strtolower($column['transport_id']));

            // Check if the from and to locations exist based on the name
            $fromLocation = Location::where('name', $fromLocationName)->first();
            $toLocation = Location::where('name', $toLocationName)->first();

            // If from/to location is not found, skip the current row
            if (!$fromLocation || !$toLocation) {
                Toast::title('Invalid from_location or to_location! Please check the locations.')
                    ->danger()
                    ->rightBottom()
                    ->autoDismiss(5);
                continue; // Skip this iteration
            }

            // Create or retrieve the transport if all required fields are present
            if (!empty($column['vehicle_seating_capacity']) && !empty($column['vehicle_luggage_capacity'])  && !empty($column['transport_id']) && !empty($column['vehicle_make'])) {
                $transport = Transport::firstOrCreate(
                    [
                        'vehicle_model' => trim(strtolower($column['transport_id'])),
                        'vehicle_make' => trim(strtolower($column['vehicle_make'])),
                        'user_id' => auth()->id(),
                    ],
                    [
                        'vehicle_model' => trim(strtolower($column['transport_id'])), // Ensure Vehicle Model
                    ]
                );
            } else {
                $transport = null;
            }

            // Convert hours from fraction of days to HH:MM format
            $hours = (float) $column['hours'] ?? 0;
            $hoursDecimal = $hours * 24;
            $formattedHours = sprintf('%02d:%02d', floor($hoursDecimal), ($hoursDecimal - floor($hoursDecimal)) * 60);

            // Handle rate_type based on location names
            $rate_type = $column['rate_type'] ?: $this->getRateTypeBasedOnLocations($fromLocationName, $toLocationName);

            // Store the rate information
            $rates = Rate::firstOrCreate([
                'from_location_id' => $fromLocation->id,
                'to_location_id' => $toLocation->id,
                'transport_id' => $transport ? $transport->id : null,
                'vehicle_seating_capacity' => $column['vehicle_seating_capacity'],
                'vehicle_luggage_capacity' => $column['vehicle_luggage_capacity'],
                'rate' => $column['rate'] ?: '0',
                'package' => $column['package'] ?: 'Package Here',
                'name' => $column['name'],
                'currency' => $column['currency'] ?: 'Currency Here',
                'effective_date' => $this->convertDate($column['effective_date']) ?: '1970-01-01',
                'expiry_date' => $this->convertDate($column['expiry_date']),
                'route_type' => $column['route_type'] ?: 'one_way',
                'time_remarks' => $column['time_remarks'],
                'remarks' => $column['remarks'],
                'hours' => $formattedHours,
                'rate_type' => $rate_type,
            ]);

            // Attach transport if it exists
            if ($transport) {
                $rates->transport()->attach($transport->id);
            }
        }
    }

    private function getRateTypeBasedOnLocations($fromLocationName, $toLocationName)
    {
        if (strpos($fromLocationName, 'airport') !== false || strpos($toLocationName, 'airport') !== false) {
            return 'airport_transfer';
        }

        if (strpos($fromLocationName, 'hotel') !== false || strpos($toLocationName, 'hotel') !== false) {
            return 'hotel_transfer';
        }

        return 'hotel_transfer'; // Default
    }

    private function convertDate($date)
    {
        return \Carbon\Carbon::parse($date)->format('Y-m-d');
    }
}
