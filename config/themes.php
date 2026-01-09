<?php

return [
    'default' => 'pixel-cream',
    'themes' => [
        'pixel-cream' => [
            'name' => 'Pixel Cream',
            'colors' => [
                'background' => '#f5f3ee',
                'surface' => '#e6e2d8',
                'accent' => '#0b8b7f',
                'secondary_accent' => '#087a70',
                'text' => '#1c201c',
                'text_muted' => '#4a5a52', // Darkened for better contrast (WCAG 2.1 AA: 4.5:1)
                'border' => '#cdc9bf',
                'darkest' => '#11130f',
            ],
        ],
        'pixel-dark' => [
            'name' => 'Pixel Dark',
            'colors' => [
                'background' => '#11130f',
                'surface' => '#1a2330',
                'accent' => '#0b8b7f',
                'secondary_accent' => '#5eb8ad',
                'text' => '#d4d9c4',
                'text_muted' => '#7a9088',
                'border' => '#2a3542',
                'darkest' => '#e9ecb1',
            ],
        ],
    ],
];
