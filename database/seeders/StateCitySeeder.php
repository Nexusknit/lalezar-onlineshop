<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\State;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StateCitySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $states = [
            [
                'name' => 'Tehran',
                'code' => 'THR',
                'cities' => [
                    ['name' => 'Tehran', 'is_capital' => true],
                    ['name' => 'Rey'],
                    ['name' => 'Eslamshahr'],
                    ['name' => 'Shahriar'],
                    ['name' => 'Varamin'],
                    ['name' => 'Damavand'],
                ],
            ],
            [
                'name' => 'Alborz',
                'code' => 'ALB',
                'cities' => [
                    ['name' => 'Karaj', 'is_capital' => true],
                    ['name' => 'Fardis'],
                    ['name' => 'Nazarabad'],
                    ['name' => 'Savojbolagh'],
                    ['name' => 'Taleghan'],
                    ['name' => 'Eshtehard'],
                ],
            ],
            [
                'name' => 'Qom',
                'code' => 'QOM',
                'cities' => [
                    ['name' => 'Qom', 'is_capital' => true],
                    ['name' => 'Kahak'],
                    ['name' => 'Salafchegan'],
                    ['name' => 'Jafariyeh'],
                ],
            ],
            [
                'name' => 'Markazi',
                'code' => 'MRK',
                'cities' => [
                    ['name' => 'Arak', 'is_capital' => true],
                    ['name' => 'Saveh'],
                    ['name' => 'Khomein'],
                    ['name' => 'Delijan'],
                    ['name' => 'Mahallat'],
                    ['name' => 'Shazand'],
                ],
            ],
            [
                'name' => 'Gilan',
                'code' => 'GIL',
                'cities' => [
                    ['name' => 'Rasht', 'is_capital' => true],
                    ['name' => 'Bandar-e Anzali'],
                    ['name' => 'Lahijan'],
                    ['name' => 'Langrud'],
                    ['name' => 'Astara'],
                    ['name' => 'Rudbar'],
                ],
            ],
            [
                'name' => 'Mazandaran',
                'code' => 'MAZ',
                'cities' => [
                    ['name' => 'Sari', 'is_capital' => true],
                    ['name' => 'Amol'],
                    ['name' => 'Babol'],
                    ['name' => 'Babolsar'],
                    ['name' => 'Qaemshahr'],
                    ['name' => 'Tonekabon'],
                ],
            ],
            [
                'name' => 'Golestan',
                'code' => 'GLS',
                'cities' => [
                    ['name' => 'Gorgan', 'is_capital' => true],
                    ['name' => 'Gonbad-e Kavus'],
                    ['name' => 'Aliabad-e Katul'],
                    ['name' => 'Azadshahr'],
                    ['name' => 'Bandar-e Gaz'],
                    ['name' => 'Kordkuy'],
                ],
            ],
            [
                'name' => 'Ardabil',
                'code' => 'ARD',
                'cities' => [
                    ['name' => 'Ardabil', 'is_capital' => true],
                    ['name' => 'Parsabad'],
                    ['name' => 'Khalkhal'],
                    ['name' => 'Germi'],
                    ['name' => 'Meshginshahr'],
                    ['name' => 'Sarein'],
                ],
            ],
            [
                'name' => 'East Azerbaijan',
                'code' => 'EAZ',
                'cities' => [
                    ['name' => 'Tabriz', 'is_capital' => true],
                    ['name' => 'Maragheh'],
                    ['name' => 'Marand'],
                    ['name' => 'Ahar'],
                    ['name' => 'Bonab'],
                    ['name' => 'Jolfa'],
                ],
            ],
            [
                'name' => 'West Azerbaijan',
                'code' => 'WAZ',
                'cities' => [
                    ['name' => 'Urmia', 'is_capital' => true],
                    ['name' => 'Khoy'],
                    ['name' => 'Mahabad'],
                    ['name' => 'Miandoab'],
                    ['name' => 'Piranshahr'],
                    ['name' => 'Sardasht'],
                ],
            ],
            [
                'name' => 'Zanjan',
                'code' => 'ZAN',
                'cities' => [
                    ['name' => 'Zanjan', 'is_capital' => true],
                    ['name' => 'Abhar'],
                    ['name' => 'Khodabandeh'],
                    ['name' => 'Mahneshan'],
                    ['name' => 'Tarom'],
                ],
            ],
            [
                'name' => 'Kurdistan',
                'code' => 'KRD',
                'cities' => [
                    ['name' => 'Sanandaj', 'is_capital' => true],
                    ['name' => 'Saqqez'],
                    ['name' => 'Baneh'],
                    ['name' => 'Marivan'],
                    ['name' => 'Divandarreh'],
                    ['name' => 'Qorveh'],
                ],
            ],
            [
                'name' => 'Kermanshah',
                'code' => 'KRH',
                'cities' => [
                    ['name' => 'Kermanshah', 'is_capital' => true],
                    ['name' => 'Eslamabad-e Gharb'],
                    ['name' => 'Javanrud'],
                    ['name' => 'Paveh'],
                    ['name' => 'Sahneh'],
                    ['name' => 'Sonqor'],
                ],
            ],
            [
                'name' => 'Hamedan',
                'code' => 'HAM',
                'cities' => [
                    ['name' => 'Hamedan', 'is_capital' => true],
                    ['name' => 'Malayer'],
                    ['name' => 'Nahavand'],
                    ['name' => 'Tuyserkan'],
                    ['name' => 'Kabudarahang'],
                    ['name' => 'Asadabad'],
                ],
            ],
            [
                'name' => 'Lorestan',
                'code' => 'LOR',
                'cities' => [
                    ['name' => 'Khorramabad', 'is_capital' => true],
                    ['name' => 'Borujerd'],
                    ['name' => 'Dorud'],
                    ['name' => 'Aligudarz'],
                    ['name' => 'Kuhdasht'],
                    ['name' => 'Azna'],
                ],
            ],
            [
                'name' => 'Ilam',
                'code' => 'ILM',
                'cities' => [
                    ['name' => 'Ilam', 'is_capital' => true],
                    ['name' => 'Dehloran'],
                    ['name' => 'Mehran'],
                    ['name' => 'Abdanan'],
                    ['name' => 'Darreh Shahr'],
                    ['name' => 'Eyvan'],
                ],
            ],
            [
                'name' => 'Khuzestan',
                'code' => 'KHO',
                'cities' => [
                    ['name' => 'Ahvaz', 'is_capital' => true],
                    ['name' => 'Abadan'],
                    ['name' => 'Khorramshahr'],
                    ['name' => 'Dezful'],
                    ['name' => 'Andimeshk'],
                    ['name' => 'Masjed Soleyman'],
                ],
            ],
            [
                'name' => 'Chaharmahal and Bakhtiari',
                'code' => 'CHB',
                'cities' => [
                    ['name' => 'Shahrekord', 'is_capital' => true],
                    ['name' => 'Borujen'],
                    ['name' => 'Lordegan'],
                    ['name' => 'Farsan'],
                    ['name' => 'Ardal'],
                    ['name' => 'Kiar'],
                ],
            ],
            [
                'name' => 'Kohgiluyeh and Boyer-Ahmad',
                'code' => 'KBA',
                'cities' => [
                    ['name' => 'Yasuj', 'is_capital' => true],
                    ['name' => 'Dogonbadan'],
                    ['name' => 'Dehdasht'],
                    ['name' => 'Landeh'],
                    ['name' => 'Basht'],
                ],
            ],
            [
                'name' => 'Isfahan',
                'code' => 'ISF',
                'cities' => [
                    ['name' => 'Isfahan', 'is_capital' => true],
                    ['name' => 'Kashan'],
                    ['name' => 'Najafabad'],
                    ['name' => 'Khomeini Shahr'],
                    ['name' => 'Shahin Shahr'],
                    ['name' => 'Mobarakeh'],
                ],
            ],
            [
                'name' => 'Fars',
                'code' => 'FAR',
                'cities' => [
                    ['name' => 'Shiraz', 'is_capital' => true],
                    ['name' => 'Marvdasht'],
                    ['name' => 'Jahrom'],
                    ['name' => 'Fasa'],
                    ['name' => 'Kazerun'],
                    ['name' => 'Lar'],
                ],
            ],
            [
                'name' => 'Bushehr',
                'code' => 'BSH',
                'cities' => [
                    ['name' => 'Bushehr', 'is_capital' => true],
                    ['name' => 'Borazjan'],
                    ['name' => 'Ganaveh'],
                    ['name' => 'Khormuj'],
                    ['name' => 'Deyr'],
                    ['name' => 'Kangan'],
                ],
            ],
            [
                'name' => 'Hormozgan',
                'code' => 'HRM',
                'cities' => [
                    ['name' => 'Bandar Abbas', 'is_capital' => true],
                    ['name' => 'Minab'],
                    ['name' => 'Qeshm'],
                    ['name' => 'Bandar Lengeh'],
                    ['name' => 'Hajiabad'],
                    ['name' => 'Rudan'],
                ],
            ],
            [
                'name' => 'Sistan and Baluchestan',
                'code' => 'SBL',
                'cities' => [
                    ['name' => 'Zahedan', 'is_capital' => true],
                    ['name' => 'Zabol'],
                    ['name' => 'Chabahar'],
                    ['name' => 'Iranshahr'],
                    ['name' => 'Saravan'],
                    ['name' => 'Khash'],
                ],
            ],
            [
                'name' => 'Kerman',
                'code' => 'KER',
                'cities' => [
                    ['name' => 'Kerman', 'is_capital' => true],
                    ['name' => 'Sirjan'],
                    ['name' => 'Rafsanjan'],
                    ['name' => 'Bam'],
                    ['name' => 'Jiroft'],
                    ['name' => 'Zarand'],
                ],
            ],
            [
                'name' => 'Yazd',
                'code' => 'YAZ',
                'cities' => [
                    ['name' => 'Yazd', 'is_capital' => true],
                    ['name' => 'Meybod'],
                    ['name' => 'Ardakan'],
                    ['name' => 'Taft'],
                    ['name' => 'Bafq'],
                    ['name' => 'Mehriz'],
                ],
            ],
            [
                'name' => 'South Khorasan',
                'code' => 'SKH',
                'cities' => [
                    ['name' => 'Birjand', 'is_capital' => true],
                    ['name' => 'Qaen'],
                    ['name' => 'Ferdows'],
                    ['name' => 'Nehbandan'],
                    ['name' => 'Tabas'],
                    ['name' => 'Sarayan'],
                ],
            ],
            [
                'name' => 'Razavi Khorasan',
                'code' => 'RKH',
                'cities' => [
                    ['name' => 'Mashhad', 'is_capital' => true],
                    ['name' => 'Neyshabur'],
                    ['name' => 'Sabzevar'],
                    ['name' => 'Kashmar'],
                    ['name' => 'Torbat-e Heydarieh'],
                    ['name' => 'Chenaran'],
                ],
            ],
            [
                'name' => 'North Khorasan',
                'code' => 'NKH',
                'cities' => [
                    ['name' => 'Bojnord', 'is_capital' => true],
                    ['name' => 'Shirvan'],
                    ['name' => 'Esfarayen'],
                    ['name' => 'Maneh va Samalqan'],
                    ['name' => 'Faruj'],
                    ['name' => 'Jajarm'],
                ],
            ],
            [
                'name' => 'Semnan',
                'code' => 'SEM',
                'cities' => [
                    ['name' => 'Semnan', 'is_capital' => true],
                    ['name' => 'Shahroud'],
                    ['name' => 'Damghan'],
                    ['name' => 'Garmsar'],
                    ['name' => 'Mehdishahr'],
                    ['name' => 'Aradan'],
                ],
            ],
            [
                'name' => 'Qazvin',
                'code' => 'QAZ',
                'cities' => [
                    ['name' => 'Qazvin', 'is_capital' => true],
                    ['name' => 'Takestan'],
                    ['name' => 'Alvand'],
                    ['name' => 'Abyek'],
                    ['name' => 'Buin Zahra'],
                    ['name' => 'Mohammadiyeh'],
                ],
            ],
        ];

        DB::transaction(function () use ($states): void {
            foreach ($states as $stateData) {
                $stateSlug = Str::slug($stateData['name']);

                $state = State::query()->updateOrCreate(
                    ['slug' => $stateSlug],
                    [
                        'name' => $stateData['name'],
                        'code' => $stateData['code'] ?? null,
                    ],
                );

                foreach ($stateData['cities'] as $cityData) {
                    $citySlug = Str::slug($stateData['name'].' '.$cityData['name']);

                    City::query()->updateOrCreate(
                        ['slug' => $citySlug],
                        [
                            'state_id' => $state->id,
                            'name' => $cityData['name'],
                            'code' => $cityData['code'] ?? null,
                            'is_capital' => (bool) ($cityData['is_capital'] ?? false),
                        ],
                    );
                }
            }
        });
    }
}
