<?php

namespace App\Livewire\Negocio\Venta;

use App\Livewire\Negocio\Venta\Concerns\ManejaMostrador;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithFileUploads;

class Mostrador extends Component
{
    use ManejaMostrador;
    use WithFileUploads;

    public function mount(): void
    {
        $this->inicializarMostrador();
    }

    public function render(): View
    {
        return view('livewire.negocio.venta.mostrador', $this->datosVistaMostrador());
    }
}
