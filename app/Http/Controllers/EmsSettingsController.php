<?php

namespace App\Http\Controllers;

use App\Models\AvailabilityUnit;
use App\Models\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmsSettingsController extends Controller
{
    public function index()
    {
        return view('ems.settings.index', [
            'locations' => Location::orderByDesc('is_active')->orderBy('name')
                ->paginate(15, ['*'], 'locations_page')->withQueryString(),
            'units' => AvailabilityUnit::orderByDesc('is_active')->orderBy('name')
                ->paginate(15, ['*'], 'units_page')->withQueryString(),
        ]);
    }

    public function storeLocation(Request $request): RedirectResponse
    {
        $name = $this->name($request, 'location_name', Location::class, 160);
        $location = Location::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();

        if ($location) {
            if ($location->is_active) {
                throw ValidationException::withMessages(['location_name' => 'This location already exists.']);
            }

            $location->update(['is_active' => true]);

            return $this->backTo('locations', $location->name.' restored.');
        }

        Location::create(['name' => $name, 'is_active' => true]);

        return $this->backTo('locations', $name.' added.');
    }

    public function updateLocation(Request $request, Location $location): RedirectResponse
    {
        $name = $this->name($request, 'location_name', Location::class, 160, $location);
        $location->update(['name' => $name]);

        return $this->backTo('locations', 'Location updated. Historical records were not changed.');
    }

    public function setLocationStatus(Request $request, Location $location): RedirectResponse
    {
        $active = $request->validate(['is_active' => ['required', 'boolean']])['is_active'];
        $location->update(['is_active' => (bool) $active]);

        return $this->backTo('locations', $location->name.((bool) $active ? ' restored.' : ' deactivated.'));
    }

    public function storeUnit(Request $request): RedirectResponse
    {
        $name = $this->name($request, 'unit_name', AvailabilityUnit::class, 120);
        $unit = AvailabilityUnit::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();

        if ($unit) {
            if ($unit->is_active) {
                throw ValidationException::withMessages(['unit_name' => 'This department or unit already exists.']);
            }

            $unit->update(['is_active' => true]);

            return $this->backTo('units', $unit->name.' restored.');
        }

        AvailabilityUnit::create(['name' => $name, 'is_active' => true]);

        return $this->backTo('units', $name.' added.');
    }

    public function updateUnit(Request $request, AvailabilityUnit $availabilityUnit): RedirectResponse
    {
        $name = $this->name($request, 'unit_name', AvailabilityUnit::class, 120, $availabilityUnit);
        $availabilityUnit->update(['name' => $name]);

        return $this->backTo('units', 'Department or unit updated. Historical records were not changed.');
    }

    public function setUnitStatus(Request $request, AvailabilityUnit $availabilityUnit): RedirectResponse
    {
        $active = $request->validate(['is_active' => ['required', 'boolean']])['is_active'];
        $availabilityUnit->update(['is_active' => (bool) $active]);

        return $this->backTo('units', $availabilityUnit->name.((bool) $active ? ' restored.' : ' deactivated.'));
    }

    /** @param class-string<Model> $model */
    private function name(Request $request, string $field, string $model, int $max, ?Model $ignore = null): string
    {
        $data = $request->validate([
            $field => ['required', 'string', 'max:'.$max, "regex:/^[\\pL\\pN .,&()\\/'-]+$/u"],
        ], [
            $field.'.regex' => 'Use letters, numbers, spaces, and standard punctuation.',
        ]);
        $name = Str::of($data[$field])->squish()->toString();
        if ($ignore) {
            $duplicate = $model::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->whereKeyNot($ignore->getKey())
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([$field => 'That name is already in use.']);
            }
        }

        return $name;
    }

    private function backTo(string $section, string $message): RedirectResponse
    {
        return redirect()->to(route('ems.settings').'#'.$section)->with('success', $message);
    }
}
