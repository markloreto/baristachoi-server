<?php

namespace App\Exports;

use App\PabileProduct;
use Maatwebsite\Excel\Concerns\FromCollection;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ProductsExport implements FromCollection, WithHeadings
{
    /**
    * @return \Illuminate\Support\Collection
    */
    public function __construct(collection $koleksyon)
    {
        $this->koleksyon = $koleksyon;
    }

    public function collection()
    {
        //return Machine::all();
        return $this->koleksyon;
    }

    public function headings(): array
	{
		return array_keys((array) $this->koleksyon->first());
	}
}