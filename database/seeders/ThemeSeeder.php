<?php

namespace Database\Seeders;

use App\Models\Theme;
use Illuminate\Database\Seeder;

class ThemeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Default Pixel Cream theme - the provided color palette
        Theme::updateOrCreate(
            ['slug' => 'pixel-cream'],
            [
                'name' => 'Pixel Cream',
                'is_active' => false,
                'colors' => [
                    'background' => '#fbf5ef',      // Lightest cream - main background
                    'surface' => '#f2d3ab',         // Tan - secondary backgrounds
                    'accent' => '#c69fa5',          // Pink - primary accent
                    'secondary_accent' => '#8b6d9c', // Purple - links, interactions
                    'text' => '#494d7e',            // Dark blue - primary text
                    'text_muted' => '#6b6f9e',      // Lighter blue - secondary text
                    'border' => '#d4c4b0',          // Warm border color
                    'darkest' => '#272744',         // Darkest - emphasis
                ],
            ]
        );

        // Dark Pixel theme - inverted/dark mode version
        Theme::updateOrCreate(
            ['slug' => 'pixel-dark'],
            [
                'name' => 'Pixel Dark',
                'is_active' => false,
                'colors' => [
                    'background' => '#272744',      // Darkest as background
                    'surface' => '#494d7e',         // Dark blue surface
                    'accent' => '#c69fa5',          // Pink accent stays
                    'secondary_accent' => '#8b6d9c', // Purple stays
                    'text' => '#fbf5ef',            // Cream as text
                    'text_muted' => '#d4c4b0',      // Muted text
                    'border' => '#6b6f9e',          // Blue-ish border
                    'darkest' => '#fbf5ef',         // Lightest for emphasis
                ],
            ]
        );

        // Retro Green - CRT monitor inspired
        Theme::updateOrCreate(
            ['slug' => 'retro-green'],
            [
                'name' => 'Retro Green',
                'is_active' => false,
                'colors' => [
                    'background' => '#0a1612',
                    'surface' => '#122620',
                    'accent' => '#4ade80',
                    'secondary_accent' => '#86efac',
                    'text' => '#dcfce7',
                    'text_muted' => '#86efac',
                    'border' => '#22543d',
                    'darkest' => '#4ade80',
                ],
            ]
        );

        // Deep Night - Dark blue/purple theme
        Theme::updateOrCreate(
            ['slug' => 'deep-night'],
            [
                'name' => 'Deep Night',
                'is_active' => false,
                'colors' => [
                    'background' => '#12173d',      // Deep navy
                    'surface' => '#293268',         // Medium dark blue
                    'accent' => '#909edd',          // Light blue-purple
                    'secondary_accent' => '#6b74b2', // Medium purple-blue
                    'text' => '#c1d9f2',            // Light blue-white
                    'text_muted' => '#909edd',      // Muted light blue
                    'border' => '#464b8c',          // Dark purple-blue
                    'darkest' => '#000000',         // Pure black for emphasis
                ],
            ]
        );

        // Celestial Light - Light blue/white theme
        Theme::updateOrCreate(
            ['slug' => 'celestial-light'],
            [
                'name' => 'Celestial Light',
                'is_active' => false,
                'colors' => [
                    'background' => '#ffffff',      // Pure white
                    'surface' => '#c1d9f2',         // Light blue
                    'accent' => '#50b9eb',          // Bright cyan
                    'secondary_accent' => '#3e83d1', // Medium blue
                    'text' => '#21526b',            // Dark teal-blue
                    'text_muted' => '#3b768f',      // Medium teal
                    'border' => '#8cdaff',          // Light cyan
                    'darkest' => '#163755',         // Very dark blue
                ],
            ]
        );

        // Neon Dreams - Vibrant purple/pink theme
        Theme::updateOrCreate(
            ['slug' => 'neon-dreams'],
            [
                'name' => 'Neon Dreams',
                'is_active' => false,
                'colors' => [
                    'background' => '#1d1a59',      // Dark purple
                    'surface' => '#3c2c68',         // Medium dark purple
                    'accent' => '#b483ef',          // Bright purple
                    'secondary_accent' => '#ff6eaf', // Hot pink
                    'text' => '#ffa5d5',            // Light pink
                    'text_muted' => '#e54286',      // Medium pink
                    'border' => '#854cbf',          // Medium purple
                    'darkest' => '#431e66',         // Very dark purple
                ],
            ]
        );

        // Ocean Depths - Teal/cyan theme
        Theme::updateOrCreate(
            ['slug' => 'ocean-depths'],
            [
                'name' => 'Ocean Depths',
                'is_active' => false,
                'colors' => [
                    'background' => '#0a2a33',      // Very dark teal
                    'surface' => '#163755',         // Dark teal-blue
                    'accent' => '#27d3cb',          // Bright cyan
                    'secondary_accent' => '#78fae6', // Light cyan
                    'text' => '#8cdaff',            // Light blue
                    'text_muted' => '#50b9eb',      // Medium cyan
                    'border' => '#00aaa5',          // Medium teal
                    'darkest' => '#0f4a4c',         // Dark teal
                ],
            ]
        );

        // Forest Canopy - Green theme
        Theme::updateOrCreate(
            ['slug' => 'forest-canopy'],
            [
                'name' => 'Forest Canopy',
                'is_active' => false,
                'colors' => [
                    'background' => '#353f23',      // Dark green
                    'surface' => '#5c5d41',         // Medium dark green
                    'accent' => '#8cff9b',          // Bright green
                    'secondary_accent' => '#42bc7f', // Medium green
                    'text' => '#afd370',            // Light green
                    'text_muted' => '#919b45',      // Yellow-green
                    'border' => '#22896e',          // Medium teal-green
                    'darkest' => '#14665b',         // Very dark green
                ],
            ]
        );

        // Sunset Glow - Warm orange/red theme
        Theme::updateOrCreate(
            ['slug' => 'sunset-glow'],
            [
                'name' => 'Sunset Glow',
                'is_active' => false,
                'colors' => [
                    'background' => '#61393b',      // Dark red-brown
                    'surface' => '#895654',         // Medium red-brown
                    'accent' => '#ffaa6e',          // Bright orange
                    'secondary_accent' => '#ff695a', // Coral red
                    'text' => '#ffccd0',            // Light pink
                    'text_muted' => '#f29faa',      // Medium pink
                    'border' => '#cc817a',          // Medium coral
                    'darkest' => '#3f1f3c',         // Very dark red-purple
                ],
            ]
        );

        // Lavender Mist - Soft purple/pink theme
        Theme::updateOrCreate(
            ['slug' => 'lavender-mist'],
            [
                'name' => 'Lavender Mist',
                'is_active' => false,
                'colors' => [
                    'background' => '#ffffff',      // White
                    'surface' => '#ffccd0',         // Light pink
                    'accent' => '#a293c4',          // Lavender
                    'secondary_accent' => '#b483ef', // Bright purple
                    'text' => '#53427f',            // Dark purple
                    'text_muted' => '#7b6aa5',      // Medium purple
                    'border' => '#ffd3ad',          // Light peach
                    'darkest' => '#431e66',         // Very dark purple
                ],
            ]
        );

        // Electric Blue - Bright blue/cyan theme
        Theme::updateOrCreate(
            ['slug' => 'electric-blue'],
            [
                'name' => 'Electric Blue',
                'is_active' => false,
                'colors' => [
                    'background' => '#12173d',      // Dark navy
                    'surface' => '#293268',         // Medium dark blue
                    'accent' => '#50b9eb',          // Bright cyan
                    'secondary_accent' => '#8cdaff', // Light cyan
                    'text' => '#c1d9f2',            // Light blue-white
                    'text_muted' => '#909edd',      // Light purple-blue
                    'border' => '#3e83d1',          // Medium blue
                    'darkest' => '#000000',         // Black
                ],
            ]
        );

        // Cherry Blossom - Soft pink theme
        Theme::updateOrCreate(
            ['slug' => 'cherry-blossom'],
            [
                'name' => 'Cherry Blossom',
                'is_active' => false,
                'colors' => [
                    'background' => '#ffffff',      // White
                    'surface' => '#ffccd0',         // Light pink
                    'accent' => '#ff6eaf',          // Bright pink
                    'secondary_accent' => '#e54286', // Medium pink
                    'text' => '#721c2f',            // Dark red-pink
                    'text_muted' => '#b22e69',      // Medium dark pink
                    'border' => '#ffa5d5',          // Light pink
                    'darkest' => '#3f1f3c',         // Very dark purple-red
                ],
            ]
        );

        // Set Pixel Cream as active by default if no theme is active
        $activeTheme = Theme::where('is_active', true)->first();
        if (! $activeTheme) {
            Theme::where('slug', 'pixel-cream')->update(['is_active' => true]);
        }
    }
}
