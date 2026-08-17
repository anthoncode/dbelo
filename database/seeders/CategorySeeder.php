<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    /**
     * Top level categories with their Font Awesome icon, each with children.
     * Content is in English because that is the language of the site.
     */
    public function run(): void
    {
        $tree = [
            'Ambience' => ['waveform-lines', ['Room Tone', 'Forest', 'City', 'Ocean', 'Crowd']],
            'Animals' => ['paw', ['Dogs', 'Cats', 'Birds', 'Insects', 'Farm']],
            'Cartoon' => ['face-laugh', ['Boings', 'Pops', 'Slides', 'Zips']],
            'Cinematic' => ['film', ['Risers', 'Impacts', 'Drones', 'Whooshes', 'Stingers']],
            'Foley' => ['shoe-prints', ['Footsteps', 'Clothing', 'Paper', 'Glass', 'Wood']],
            'Horror' => ['ghost', ['Screams', 'Whispers', 'Creaks', 'Tension']],
            'Human' => ['person', ['Voices', 'Breathing', 'Laughter', 'Applause']],
            'Interface' => ['sliders', ['Clicks', 'Notifications', 'Errors', 'Transitions']],
            'Machines' => ['gears', ['Engines', 'Tools', 'Appliances', 'Servos']],
            'Nature' => ['tree', ['Rain', 'Thunder', 'Wind', 'Fire', 'Water']],
            'Sci-Fi' => ['rocket', ['Lasers', 'Spaceships', 'Robots', 'Teleport', 'Alarms']],
            'Transportation' => ['car', ['Cars', 'Trains', 'Planes', 'Bicycles']],
            'Weapons' => ['crosshairs', ['Guns', 'Swords', 'Explosions', 'Punches']],
        ];

        $order = 0;

        foreach ($tree as $parentName => [$icon, $children]) {
            $parent = Category::updateOrCreate(
                ['slug' => Str::slug($parentName)],
                [
                    'name' => $parentName,
                    'parent_id' => null,
                    'icon' => $icon,
                    'sort_order' => $order++,
                ]
            );

            $childOrder = 0;

            foreach ($children as $childName) {
                Category::updateOrCreate(
                    ['slug' => Str::slug($parentName.' '.$childName)],
                    [
                        'name' => $childName,
                        'parent_id' => $parent->id,
                        'sort_order' => $childOrder++,
                    ]
                );
            }
        }
    }
}
