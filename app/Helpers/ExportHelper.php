<?php

namespace App\Helpers;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ExportHelper implements FromCollection, WithHeadings
{
    protected $data;
    protected $headings;

    public function __construct($data, $headings)
    {
        $this->data = $data;
        $this->headings = $headings;
    }

    // This method returns the collection of data to be exported
    public function collection()
    {
        return collect($this->data);
    }

    // This method returns the headings for the export file
    public function headings(): array
    {
        return $this->headings;
    }
}
