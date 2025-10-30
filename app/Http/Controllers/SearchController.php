<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\SearchService;


class SearchController extends Controller
{
    protected $searchService;

    public function __construct(SearchService $searchService)
    {
        $this->searchService = $searchService;
    }

    public function search(Request $request)
    {
        $locations = $this->searchService->searchLocations($request->input('query'));
        return response()->json($locations);
    }

    public function searchCities(Request $request)
    {
        $cities = $this->searchService->searchCities($request->input('query'));
        return response()->json($cities);
    }

    public function searchCityAndHotel(Request $request)
    {
        $cities = $this->searchService->searchCityAndHotel($request->input('query'));
        return response()->json($cities);
    }
    public function searchContractulCityAndHotel(Request $request)
    {
        $cities = $this->searchService->searchContractulCityAndHotel($request->input('query'));
        return response()->json($cities);
    }

    public function searchCountries(Request $request)
    {
        $countries = $this->searchService->searchCountries($request->input('query'));
        return response()->json($countries);
    }

}
