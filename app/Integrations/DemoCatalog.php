<?php

namespace App\Integrations;

/** The made-up hotels, room types and airports the two pretend booking sites offer. */
final class DemoCatalog
{
    public const HOTELS = [
        'sunrise' => ['name' => 'Hotel Sunrise', 'address' => 'Candolim, Goa'],
        'aurora' => ['name' => 'Beach Villa Aurora', 'address' => 'Palolem, Goa'],
        'hilltop' => ['name' => 'Hilltop Lodge', 'address' => 'Madikeri, Coorg, Karnataka'],
    ];

    public const ROOM_TYPES = ['Sea view', 'Garden view', 'Standard'];

    public const AIRPORTS = [
        'GOI' => 'Goa',
        'BLR' => 'Bengaluru',
        'BOM' => 'Mumbai',
        'DEL' => 'Delhi',
        'HYD' => 'Hyderabad',
    ];

    public const DEPARTURE_TIMES = ['06:30', '09:15', '14:40', '18:30', '21:10'];
}
