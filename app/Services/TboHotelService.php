<?php

    class TboHotelService
    {
        protected $baseUrl = 'http://api.tbotechnology.in/';
        protected $username;
        protected $password;

        public function __construct()
        {
            $this->username = config('services.tbo.username');
            $this->password = config('services.tbo.password');
        }

        public function getHotelsByCityCode(string $cityCode)
        {
            $response = Http::withBasicAuth($this->username, $this->password)
                ->acceptJson()
                ->post($this->baseUrl . 'TBOHolidays_HotelAPI/TBOHotelCodeList', [
                    'CityCode' => $cityCode,
                    'IsDetailedResponse' => true,
                ]);

            if ($response->successful()) {
                return $response->json();
            }

            throw new \Exception('TBO API request failed: ' . $response->body());
        }
    }

?>