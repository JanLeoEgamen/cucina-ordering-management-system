<?php

// The colour themes an admin can pick in System Configuration -> Branding (App\Support\Theme prints
// the one that is saved as CSS variables on every page; tailwind.config.js reads them).
//
// A theme is the BRAND colour: the Tailwind `indigo` scale (links, rings, soft washes, chips: 50..950)
// plus the ACCENT, the solid fill of primary buttons and of the page you are on in the menu, with the
// text on it. The page itself (the warm paper / gray scale) never changes. Dark mode has its own accent
// (a lighter shade, so a solid button does not vanish on the dark page) and its own text on it.
//
// `ink` is the original look (no colour: near-black on paper, mist on the dark page) and the default.
// The others come from Tailwind's own palettes; the shade used as 600 is the first one (600..800) that
// keeps white text at 4.5:1 or better, so every button stays readable. `hero` (the big dark panels) is optional: without it
// it is the accent DEEPENED (Theme::deepen(), 45% towards black), so a panel is a rich shade of the colour, not near-black. A theme may also carry a `gradient` (from / to for the light page,
// dark_from / dark_to for the dark one; hero_from / hero_to for the big dark panels are optional and default to the two ends deepened): then every solid accent surface
// and the hero panels are painted with it, and `accent` stays its first colour for borders, rings and text. To add a theme add a block here:
// nothing else needs to change (the dropdown, the preview and the validation read this file).
return [
    'default' => 'ink',

    'themes' => [
        'ink' => [
            'label' => 'Ink',
            'scale' => [50 => '#f3f2ee', 100 => '#e9e7e0', 200 => '#dcd9cf', 300 => '#c9c5b8', 400 => '#a8a498', 500 => '#6b6a64', 600 => '#17170f', 700 => '#2b2b20', 800 => '#55544e', 900 => '#1d1d14', 950 => '#0f0f0a'],
            'hero' => '#17170f',
            'accent' => '#17170f',
            'accent_hover' => '#3a3933',
            'accent_on' => '#ffffff',
            'dark_accent' => '#e8e6e1',
            'dark_accent_hover' => '#ffffff',
            'dark_accent_on' => '#17170f',
        ],

    'blue' => [
        'label' => 'Blue',
        'scale' => [50 => '#eff6ff', 100 => '#dbeafe', 200 => '#bfdbfe', 300 => '#93c5fd', 400 => '#60a5fa', 500 => '#3b82f6', 600 => '#2563eb', 700 => '#1d4ed8', 800 => '#1e40af', 900 => '#1e3a8a', 950 => '#172554'],
        'accent' => '#2563eb',
        'accent_hover' => '#1d4ed8',
        'accent_on' => '#ffffff',
        'dark_accent' => '#60a5fa',
        'dark_accent_hover' => '#93c5fd',
        'dark_accent_on' => '#141413',
    ],

    'indigo' => [
        'label' => 'Indigo',
        'scale' => [50 => '#eef2ff', 100 => '#e0e7ff', 200 => '#c7d2fe', 300 => '#a5b4fc', 400 => '#818cf8', 500 => '#6366f1', 600 => '#4f46e5', 700 => '#4338ca', 800 => '#3730a3', 900 => '#312e81', 950 => '#1e1b4b'],
        'accent' => '#4f46e5',
        'accent_hover' => '#4338ca',
        'accent_on' => '#ffffff',
        'dark_accent' => '#818cf8',
        'dark_accent_hover' => '#a5b4fc',
        'dark_accent_on' => '#141413',
    ],

    'violet' => [
        'label' => 'Violet',
        'scale' => [50 => '#f5f3ff', 100 => '#ede9fe', 200 => '#ddd6fe', 300 => '#c4b5fd', 400 => '#a78bfa', 500 => '#8b5cf6', 600 => '#7c3aed', 700 => '#6d28d9', 800 => '#5b21b6', 900 => '#4c1d95', 950 => '#2e1065'],
        'accent' => '#7c3aed',
        'accent_hover' => '#6d28d9',
        'accent_on' => '#ffffff',
        'dark_accent' => '#a78bfa',
        'dark_accent_hover' => '#c4b5fd',
        'dark_accent_on' => '#141413',
    ],

    'rose' => [
        'label' => 'Rose',
        'scale' => [50 => '#fff1f2', 100 => '#ffe4e6', 200 => '#fecdd3', 300 => '#fda4af', 400 => '#fb7185', 500 => '#f43f5e', 600 => '#e11d48', 700 => '#be123c', 800 => '#9f1239', 900 => '#881337', 950 => '#4c0519'],
        'accent' => '#e11d48',
        'accent_hover' => '#be123c',
        'accent_on' => '#ffffff',
        'dark_accent' => '#fb7185',
        'dark_accent_hover' => '#fda4af',
        'dark_accent_on' => '#141413',
    ],

    'orange' => [
        'label' => 'Orange',
        'scale' => [50 => '#fff7ed', 100 => '#ffedd5', 200 => '#fed7aa', 300 => '#fdba74', 400 => '#fb923c', 500 => '#ea580c', 600 => '#c2410c', 700 => '#9a3412', 800 => '#7c2d12', 900 => '#431407', 950 => '#431407'],
        'accent' => '#c2410c',
        'accent_hover' => '#9a3412',
        'accent_on' => '#ffffff',
        'dark_accent' => '#fb923c',
        'dark_accent_hover' => '#fdba74',
        'dark_accent_on' => '#141413',
    ],

    'amber' => [
        'label' => 'Amber',
        'scale' => [50 => '#fffbeb', 100 => '#fef3c7', 200 => '#fde68a', 300 => '#fcd34d', 400 => '#fbbf24', 500 => '#d97706', 600 => '#b45309', 700 => '#92400e', 800 => '#78350f', 900 => '#451a03', 950 => '#451a03'],
        'accent' => '#b45309',
        'accent_hover' => '#92400e',
        'accent_on' => '#ffffff',
        'dark_accent' => '#fbbf24',
        'dark_accent_hover' => '#fcd34d',
        'dark_accent_on' => '#141413',
    ],

    'emerald' => [
        'label' => 'Emerald',
        'scale' => [50 => '#ecfdf5', 100 => '#d1fae5', 200 => '#a7f3d0', 300 => '#6ee7b7', 400 => '#34d399', 500 => '#059669', 600 => '#047857', 700 => '#065f46', 800 => '#064e3b', 900 => '#022c22', 950 => '#022c22'],
        'accent' => '#047857',
        'accent_hover' => '#065f46',
        'accent_on' => '#ffffff',
        'dark_accent' => '#34d399',
        'dark_accent_hover' => '#6ee7b7',
        'dark_accent_on' => '#141413',
    ],

    'teal' => [
        'label' => 'Teal',
        'scale' => [50 => '#f0fdfa', 100 => '#ccfbf1', 200 => '#99f6e4', 300 => '#5eead4', 400 => '#2dd4bf', 500 => '#0d9488', 600 => '#0f766e', 700 => '#115e59', 800 => '#134e4a', 900 => '#042f2e', 950 => '#042f2e'],
        'accent' => '#0f766e',
        'accent_hover' => '#115e59',
        'accent_on' => '#ffffff',
        'dark_accent' => '#2dd4bf',
        'dark_accent_hover' => '#5eead4',
        'dark_accent_on' => '#141413',
    ],

        'terracotta' => [
            'label' => 'Terracotta',
            'scale' => [50 => '#fbf6f4', 100 => '#f5e9e5', 200 => '#efcec3', 300 => '#e3a996', 400 => '#d7856a', 500 => '#cc613e', 600 => '#a94c2d', 700 => '#8d3f25', 800 => '#71331e', 900 => '#552616', 950 => '#30160d'],
            'accent' => '#a94c2d',
            'accent_hover' => '#8d3f25',
            'accent_on' => '#ffffff',
            'dark_accent' => '#d7856a',
            'dark_accent_hover' => '#e3a996',
            'dark_accent_on' => '#141413',
        ],

        'coffee' => [
            'label' => 'Coffee',
            'scale' => [50 => '#faf7f4', 100 => '#f4ede6', 200 => '#e7d8ca', 300 => '#d6bba4', 400 => '#c59e7d', 500 => '#b38156', 600 => '#946842', 700 => '#7b5737', 800 => '#63462c', 900 => '#4a3421', 950 => '#2a1e13'],
            'accent' => '#946842',
            'accent_hover' => '#7b5737',
            'accent_on' => '#ffffff',
            'dark_accent' => '#c59e7d',
            'dark_accent_hover' => '#d6bba4',
            'dark_accent_on' => '#141413',
        ],

        'olive' => [
            'label' => 'Olive',
            'scale' => [50 => '#f9faf4', 100 => '#f0f4e6', 200 => '#dfe8c9', 300 => '#c7d7a2', 400 => '#b0c67b', 500 => '#7c9640', 600 => '#687d36', 700 => '#53642b', 800 => '#3e4b20', 900 => '#232b12', 950 => '#232b12'],
            'accent' => '#687d36',
            'accent_hover' => '#53642b',
            'accent_on' => '#ffffff',
            'dark_accent' => '#b0c67b',
            'dark_accent_hover' => '#c7d7a2',
            'dark_accent_on' => '#141413',
        ],

        'sage' => [
            'label' => 'Sage',
            'scale' => [50 => '#f6f9f7', 100 => '#e9f1ed', 200 => '#d0e2d9', 300 => '#adcdbd', 400 => '#8ab7a1', 500 => '#51856b', 600 => '#446f59', 700 => '#365947', 800 => '#294236', 900 => '#17261f', 950 => '#17261f'],
            'accent' => '#446f59',
            'accent_hover' => '#365947',
            'accent_on' => '#ffffff',
            'dark_accent' => '#8ab7a1',
            'dark_accent_hover' => '#adcdbd',
            'dark_accent_on' => '#141413',
        ],

        'plum' => [
            'label' => 'Plum',
            'scale' => [50 => '#faf5fa', 100 => '#f4e7f3', 200 => '#e7cbe4', 300 => '#d5a5d1', 400 => '#c37fbd', 500 => '#b159a9', 600 => '#92458b', 700 => '#793974', 800 => '#612e5d', 900 => '#492246', 950 => '#2a1428'],
            'accent' => '#92458b',
            'accent_hover' => '#793974',
            'accent_on' => '#ffffff',
            'dark_accent' => '#c37fbd',
            'dark_accent_hover' => '#d5a5d1',
            'dark_accent_on' => '#141413',
        ],

        'navy' => [
            'label' => 'Navy',
            'scale' => [50 => '#f4f6fb', 100 => '#e5eaf5', 200 => '#c3d0ef', 300 => '#96ade3', 400 => '#6a8bd7', 500 => '#3e68cc', 600 => '#2d52a9', 700 => '#25458d', 800 => '#1e3771', 900 => '#162955', 950 => '#0d1830'],
            'accent' => '#2d52a9',
            'accent_hover' => '#25458d',
            'accent_on' => '#ffffff',
            'dark_accent' => '#6a8bd7',
            'dark_accent_hover' => '#96ade3',
            'dark_accent_on' => '#141413',
        ],

        'sky' => [
            'label' => 'Sky',
            'scale' => [50 => '#f0f9ff', 100 => '#e0f2fe', 200 => '#bae6fd', 300 => '#7dd3fc', 400 => '#38bdf8', 500 => '#0284c7', 600 => '#0369a1', 700 => '#075985', 800 => '#0c4a6e', 900 => '#082f49', 950 => '#082f49'],
            'accent' => '#0369a1',
            'accent_hover' => '#075985',
            'accent_on' => '#ffffff',
            'dark_accent' => '#38bdf8',
            'dark_accent_hover' => '#7dd3fc',
            'dark_accent_on' => '#141413',
        ],

        'cyan' => [
            'label' => 'Cyan',
            'scale' => [50 => '#ecfeff', 100 => '#cffafe', 200 => '#a5f3fc', 300 => '#67e8f9', 400 => '#22d3ee', 500 => '#0891b2', 600 => '#0e7490', 700 => '#155e75', 800 => '#164e63', 900 => '#083344', 950 => '#083344'],
            'accent' => '#0e7490',
            'accent_hover' => '#155e75',
            'accent_on' => '#ffffff',
            'dark_accent' => '#22d3ee',
            'dark_accent_hover' => '#67e8f9',
            'dark_accent_on' => '#141413',
        ],

        'green' => [
            'label' => 'Green',
            'scale' => [50 => '#f0fdf4', 100 => '#dcfce7', 200 => '#bbf7d0', 300 => '#86efac', 400 => '#4ade80', 500 => '#16a34a', 600 => '#15803d', 700 => '#166534', 800 => '#14532d', 900 => '#052e16', 950 => '#052e16'],
            'accent' => '#15803d',
            'accent_hover' => '#166534',
            'accent_on' => '#ffffff',
            'dark_accent' => '#4ade80',
            'dark_accent_hover' => '#86efac',
            'dark_accent_on' => '#141413',
        ],

        'pink' => [
            'label' => 'Pink',
            'scale' => [50 => '#fdf2f8', 100 => '#fce7f3', 200 => '#fbcfe8', 300 => '#f9a8d4', 400 => '#f472b6', 500 => '#ec4899', 600 => '#db2777', 700 => '#be185d', 800 => '#9d174d', 900 => '#831843', 950 => '#500724'],
            'accent' => '#db2777',
            'accent_hover' => '#be185d',
            'accent_on' => '#ffffff',
            'dark_accent' => '#f472b6',
            'dark_accent_hover' => '#f9a8d4',
            'dark_accent_on' => '#141413',
        ],

        'sunset' => [
            'label' => 'Sunset',
            'scale' => [50 => '#fff7ed', 100 => '#ffedd5', 200 => '#fed7aa', 300 => '#fdba74', 400 => '#fb923c', 500 => '#ea580c', 600 => '#c2410c', 700 => '#9a3412', 800 => '#7c2d12', 900 => '#431407', 950 => '#431407'],
            'accent' => '#c2410c',
            'accent_hover' => '#9a3412',
            'accent_on' => '#ffffff',
            'dark_accent' => '#fb923c',
            'dark_accent_hover' => '#fdba74',
            'dark_accent_on' => '#141413',
            // The fill is a gradient from one colour to the other (the accent above is its first colour).
            'gradient' => ['from' => '#c2410c', 'to' => '#e11d48', 'dark_from' => '#fb923c', 'dark_to' => '#fb7185'],
        ],

        'ocean' => [
            'label' => 'Ocean',
            'scale' => [50 => '#f0f9ff', 100 => '#e0f2fe', 200 => '#bae6fd', 300 => '#7dd3fc', 400 => '#38bdf8', 500 => '#0284c7', 600 => '#0369a1', 700 => '#075985', 800 => '#0c4a6e', 900 => '#082f49', 950 => '#082f49'],
            'accent' => '#0369a1',
            'accent_hover' => '#075985',
            'accent_on' => '#ffffff',
            'dark_accent' => '#38bdf8',
            'dark_accent_hover' => '#7dd3fc',
            'dark_accent_on' => '#141413',
            // The fill is a gradient from one colour to the other (the accent above is its first colour).
            'gradient' => ['from' => '#0369a1', 'to' => '#0f766e', 'dark_from' => '#38bdf8', 'dark_to' => '#2dd4bf'],
        ],

        'aurora' => [
            'label' => 'Aurora',
            'scale' => [50 => '#ecfdf5', 100 => '#d1fae5', 200 => '#a7f3d0', 300 => '#6ee7b7', 400 => '#34d399', 500 => '#059669', 600 => '#047857', 700 => '#065f46', 800 => '#064e3b', 900 => '#022c22', 950 => '#022c22'],
            'accent' => '#047857',
            'accent_hover' => '#065f46',
            'accent_on' => '#ffffff',
            'dark_accent' => '#34d399',
            'dark_accent_hover' => '#6ee7b7',
            'dark_accent_on' => '#141413',
            // The fill is a gradient from one colour to the other (the accent above is its first colour).
            'gradient' => ['from' => '#047857', 'to' => '#2563eb', 'dark_from' => '#34d399', 'dark_to' => '#60a5fa'],
        ],

        'berry' => [
            'label' => 'Berry',
            'scale' => [50 => '#f5f3ff', 100 => '#ede9fe', 200 => '#ddd6fe', 300 => '#c4b5fd', 400 => '#a78bfa', 500 => '#8b5cf6', 600 => '#7c3aed', 700 => '#6d28d9', 800 => '#5b21b6', 900 => '#4c1d95', 950 => '#2e1065'],
            'accent' => '#7c3aed',
            'accent_hover' => '#6d28d9',
            'accent_on' => '#ffffff',
            'dark_accent' => '#a78bfa',
            'dark_accent_hover' => '#c4b5fd',
            'dark_accent_on' => '#141413',
            // The fill is a gradient from one colour to the other (the accent above is its first colour).
            'gradient' => ['from' => '#7c3aed', 'to' => '#db2777', 'dark_from' => '#a78bfa', 'dark_to' => '#f472b6'],
        ],

        'ember' => [
            'label' => 'Ember',
            'scale' => [50 => '#fffbeb', 100 => '#fef3c7', 200 => '#fde68a', 300 => '#fcd34d', 400 => '#fbbf24', 500 => '#d97706', 600 => '#b45309', 700 => '#92400e', 800 => '#78350f', 900 => '#451a03', 950 => '#451a03'],
            'accent' => '#b45309',
            'accent_hover' => '#92400e',
            'accent_on' => '#ffffff',
            'dark_accent' => '#fbbf24',
            'dark_accent_hover' => '#fcd34d',
            'dark_accent_on' => '#141413',
            // The fill is a gradient from one colour to the other (the accent above is its first colour).
            'gradient' => ['from' => '#b45309', 'to' => '#dc2626', 'dark_from' => '#fbbf24', 'dark_to' => '#f87171'],
        ],

        'twilight' => [
            'label' => 'Twilight',
            'scale' => [50 => '#eef2ff', 100 => '#e0e7ff', 200 => '#c7d2fe', 300 => '#a5b4fc', 400 => '#818cf8', 500 => '#6366f1', 600 => '#4f46e5', 700 => '#4338ca', 800 => '#3730a3', 900 => '#312e81', 950 => '#1e1b4b'],
            'accent' => '#4f46e5',
            'accent_hover' => '#4338ca',
            'accent_on' => '#ffffff',
            'dark_accent' => '#818cf8',
            'dark_accent_hover' => '#a5b4fc',
            'dark_accent_on' => '#141413',
            // The fill is a gradient from one colour to the other (the accent above is its first colour).
            'gradient' => ['from' => '#4f46e5', 'to' => '#c026d3', 'dark_from' => '#818cf8', 'dark_to' => '#e879f9'],
        ],

        'dusk' => [
            'label' => 'Dusk',
            'scale' => [50 => '#fbf6f4', 100 => '#f5e9e5', 200 => '#efcec3', 300 => '#e3a996', 400 => '#d7856a', 500 => '#cc613e', 600 => '#a94c2d', 700 => '#8d3f25', 800 => '#71331e', 900 => '#552616', 950 => '#30160d'],
            'accent' => '#a94c2d',
            'accent_hover' => '#8d3f25',
            'accent_on' => '#ffffff',
            'dark_accent' => '#d7856a',
            'dark_accent_hover' => '#e3a996',
            'dark_accent_on' => '#141413',
            // The fill is a gradient from one colour to the other (the accent above is its first colour).
            'gradient' => ['from' => '#a94c2d', 'to' => '#92458b', 'dark_from' => '#d7856a', 'dark_to' => '#c37fbd'],
        ],

        'meadow' => [
            'label' => 'Meadow',
            'scale' => [50 => '#f6f9f7', 100 => '#e9f1ed', 200 => '#d0e2d9', 300 => '#adcdbd', 400 => '#8ab7a1', 500 => '#51856b', 600 => '#446f59', 700 => '#365947', 800 => '#294236', 900 => '#17261f', 950 => '#17261f'],
            'accent' => '#446f59',
            'accent_hover' => '#365947',
            'accent_on' => '#ffffff',
            'dark_accent' => '#8ab7a1',
            'dark_accent_hover' => '#adcdbd',
            'dark_accent_on' => '#141413',
            // The fill is a gradient from one colour to the other (the accent above is its first colour).
            'gradient' => ['from' => '#446f59', 'to' => '#687d36', 'dark_from' => '#8ab7a1', 'dark_to' => '#b0c67b'],
        ],

        'red' => [
            'label' => 'Red',
            'scale' => [50 => '#fef2f2', 100 => '#fee2e2', 200 => '#fecaca', 300 => '#fca5a5', 400 => '#f87171', 500 => '#ef4444', 600 => '#dc2626', 700 => '#b91c1c', 800 => '#991b1b', 900 => '#7f1d1d', 950 => '#450a0a'],
            'accent' => '#dc2626',
            'accent_hover' => '#b91c1c',
            'accent_on' => '#ffffff',
            'dark_accent' => '#f87171',
            'dark_accent_hover' => '#fca5a5',
            'dark_accent_on' => '#141413',
        ],

        'fuchsia' => [
            'label' => 'Fuchsia',
            'scale' => [50 => '#fdf4ff', 100 => '#fae8ff', 200 => '#f5d0fe', 300 => '#f0abfc', 400 => '#e879f9', 500 => '#d946ef', 600 => '#c026d3', 700 => '#a21caf', 800 => '#86198f', 900 => '#701a75', 950 => '#4a044e'],
            'accent' => '#c026d3',
            'accent_hover' => '#a21caf',
            'accent_on' => '#ffffff',
            'dark_accent' => '#e879f9',
            'dark_accent_hover' => '#f0abfc',
            'dark_accent_on' => '#141413',
        ],

        'purple' => [
            'label' => 'Purple',
            'scale' => [50 => '#faf5ff', 100 => '#f3e8ff', 200 => '#e9d5ff', 300 => '#d8b4fe', 400 => '#c084fc', 500 => '#a855f7', 600 => '#9333ea', 700 => '#7e22ce', 800 => '#6b21a8', 900 => '#581c87', 950 => '#3b0764'],
            'accent' => '#9333ea',
            'accent_hover' => '#7e22ce',
            'accent_on' => '#ffffff',
            'dark_accent' => '#c084fc',
            'dark_accent_hover' => '#d8b4fe',
            'dark_accent_on' => '#141413',
        ],

        'lime' => [
            'label' => 'Lime',
            'scale' => [50 => '#f7fee7', 100 => '#ecfccb', 200 => '#d9f99d', 300 => '#bef264', 400 => '#a3e635', 500 => '#65a30d', 600 => '#4d7c0f', 700 => '#3f6212', 800 => '#365314', 900 => '#1a2e05', 950 => '#1a2e05'],
            'accent' => '#4d7c0f',
            'accent_hover' => '#3f6212',
            'accent_on' => '#ffffff',
            'dark_accent' => '#a3e635',
            'dark_accent_hover' => '#bef264',
            'dark_accent_on' => '#141413',
        ],

        'slate' => [
            'label' => 'Slate',
            'scale' => [50 => '#f8fafc', 100 => '#f1f5f9', 200 => '#e2e8f0', 300 => '#cbd5e1', 400 => '#94a3b8', 500 => '#64748b', 600 => '#475569', 700 => '#334155', 800 => '#1e293b', 900 => '#0f172a', 950 => '#020617'],
            'accent' => '#475569',
            'accent_hover' => '#334155',
            'accent_on' => '#ffffff',
            'dark_accent' => '#94a3b8',
            'dark_accent_hover' => '#cbd5e1',
            'dark_accent_on' => '#141413',
        ],

        'burgundy' => [
            'label' => 'Burgundy',
            'scale' => [50 => '#fbf4f6', 100 => '#f5e5e9', 200 => '#eec4ce', 300 => '#e198aa', 400 => '#d56d87', 500 => '#c84163', 600 => '#a6304e', 700 => '#8a2841', 800 => '#6f2034', 900 => '#531827', 950 => '#2f0e16'],
            'accent' => '#a6304e',
            'accent_hover' => '#8a2841',
            'accent_on' => '#ffffff',
            'dark_accent' => '#d56d87',
            'dark_accent_hover' => '#e198aa',
            'dark_accent_on' => '#141413',
        ],

        'pine' => [
            'label' => 'Pine',
            'scale' => [50 => '#f4fbf9', 100 => '#e5f5f1', 200 => '#c8eae1', 300 => '#9fdbcc', 400 => '#76cbb6', 500 => '#3b9b83', 600 => '#31816d', 700 => '#276857', 800 => '#1d4e42', 900 => '#112c25', 950 => '#112c25'],
            'accent' => '#31816d',
            'accent_hover' => '#276857',
            'accent_on' => '#ffffff',
            'dark_accent' => '#76cbb6',
            'dark_accent_hover' => '#9fdbcc',
            'dark_accent_on' => '#141413',
        ],

        'mustard' => [
            'label' => 'Mustard',
            'scale' => [50 => '#fbf9f4', 100 => '#f5f0e5', 200 => '#f4e3be', 300 => '#ebcf8e', 400 => '#e3bb5f', 500 => '#98721b', 600 => '#795b15', 700 => '#5b4510', 800 => '#342709', 900 => '#342709', 950 => '#342709'],
            'accent' => '#795b15',
            'accent_hover' => '#5b4510',
            'accent_on' => '#ffffff',
            'dark_accent' => '#e3bb5f',
            'dark_accent_hover' => '#ebcf8e',
            'dark_accent_on' => '#141413',
        ],

        'denim' => [
            'label' => 'Denim',
            'scale' => [50 => '#f4f7fb', 100 => '#e5edf5', 200 => '#c8d8ea', 300 => '#9fbbdb', 400 => '#769ecb', 500 => '#4e81bc', 600 => '#3b689b', 700 => '#315781', 800 => '#274568', 900 => '#1d344e', 950 => '#111e2c'],
            'accent' => '#3b689b',
            'accent_hover' => '#315781',
            'accent_on' => '#ffffff',
            'dark_accent' => '#769ecb',
            'dark_accent_hover' => '#9fbbdb',
            'dark_accent_on' => '#141413',
        ],

        'lavender' => [
            'label' => 'Lavender',
            'scale' => [50 => '#f7f4fa', 100 => '#ebe6f4', 200 => '#d5c9e8', 300 => '#b6a2d7', 400 => '#977bc6', 500 => '#7854b6', 600 => '#604096', 700 => '#50367d', 800 => '#402b64', 900 => '#30204b', 950 => '#1b122b'],
            'accent' => '#604096',
            'accent_hover' => '#50367d',
            'accent_on' => '#ffffff',
            'dark_accent' => '#977bc6',
            'dark_accent_hover' => '#b6a2d7',
            'dark_accent_on' => '#141413',
        ],

        'peach' => [
            'label' => 'Peach',
            'scale' => [50 => '#fff7ed', 100 => '#ffedd5', 200 => '#fed7aa', 300 => '#fdba74', 400 => '#fb923c', 500 => '#ea580c', 600 => '#c2410c', 700 => '#9a3412', 800 => '#7c2d12', 900 => '#431407', 950 => '#431407'],
            'accent' => '#c2410c',
            'accent_hover' => '#9a3412',
            'accent_on' => '#ffffff',
            'dark_accent' => '#fb923c',
            'dark_accent_hover' => '#fdba74',
            'dark_accent_on' => '#141413',
            'gradient' => ['from' => '#c2410c', 'to' => '#db2777', 'dark_from' => '#fb923c', 'dark_to' => '#f472b6'],
        ],

        'glacier' => [
            'label' => 'Glacier',
            'scale' => [50 => '#f0f9ff', 100 => '#e0f2fe', 200 => '#bae6fd', 300 => '#7dd3fc', 400 => '#38bdf8', 500 => '#0284c7', 600 => '#0369a1', 700 => '#075985', 800 => '#0c4a6e', 900 => '#082f49', 950 => '#082f49'],
            'accent' => '#0369a1',
            'accent_hover' => '#075985',
            'accent_on' => '#ffffff',
            'dark_accent' => '#38bdf8',
            'dark_accent_hover' => '#7dd3fc',
            'dark_accent_on' => '#141413',
            'gradient' => ['from' => '#0369a1', 'to' => '#4f46e5', 'dark_from' => '#38bdf8', 'dark_to' => '#818cf8'],
        ],

        'midnight' => [
            'label' => 'Midnight',
            'scale' => [50 => '#f4f6fb', 100 => '#e5eaf5', 200 => '#c3d0ef', 300 => '#96ade3', 400 => '#6a8bd7', 500 => '#3e68cc', 600 => '#2d52a9', 700 => '#25458d', 800 => '#1e3771', 900 => '#162955', 950 => '#0d1830'],
            'accent' => '#2d52a9',
            'accent_hover' => '#25458d',
            'accent_on' => '#ffffff',
            'dark_accent' => '#6a8bd7',
            'dark_accent_hover' => '#96ade3',
            'dark_accent_on' => '#141413',
            'gradient' => ['from' => '#2d52a9', 'to' => '#4f46e5', 'dark_from' => '#6a8bd7', 'dark_to' => '#818cf8'],
        ],

        'mulberry' => [
            'label' => 'Mulberry',
            'scale' => [50 => '#faf5fa', 100 => '#f4e7f3', 200 => '#e7cbe4', 300 => '#d5a5d1', 400 => '#c37fbd', 500 => '#b159a9', 600 => '#92458b', 700 => '#793974', 800 => '#612e5d', 900 => '#492246', 950 => '#2a1428'],
            'accent' => '#92458b',
            'accent_hover' => '#793974',
            'accent_on' => '#ffffff',
            'dark_accent' => '#c37fbd',
            'dark_accent_hover' => '#d5a5d1',
            'dark_accent_on' => '#141413',
            'gradient' => ['from' => '#92458b', 'to' => '#e11d48', 'dark_from' => '#c37fbd', 'dark_to' => '#fb7185'],
        ],

        'copper' => [
            'label' => 'Copper',
            'scale' => [50 => '#faf7f4', 100 => '#f4ede6', 200 => '#e7d8ca', 300 => '#d6bba4', 400 => '#c59e7d', 500 => '#b38156', 600 => '#946842', 700 => '#7b5737', 800 => '#63462c', 900 => '#4a3421', 950 => '#2a1e13'],
            'accent' => '#946842',
            'accent_hover' => '#7b5737',
            'accent_on' => '#ffffff',
            'dark_accent' => '#c59e7d',
            'dark_accent_hover' => '#d6bba4',
            'dark_accent_on' => '#141413',
            'gradient' => ['from' => '#946842', 'to' => '#b45309', 'dark_from' => '#c59e7d', 'dark_to' => '#fbbf24'],
        ],

        'citrus' => [
            'label' => 'Citrus',
            'scale' => [50 => '#f7fee7', 100 => '#ecfccb', 200 => '#d9f99d', 300 => '#bef264', 400 => '#a3e635', 500 => '#65a30d', 600 => '#4d7c0f', 700 => '#3f6212', 800 => '#365314', 900 => '#1a2e05', 950 => '#1a2e05'],
            'accent' => '#4d7c0f',
            'accent_hover' => '#3f6212',
            'accent_on' => '#ffffff',
            'dark_accent' => '#a3e635',
            'dark_accent_hover' => '#bef264',
            'dark_accent_on' => '#141413',
            'gradient' => ['from' => '#4d7c0f', 'to' => '#047857', 'dark_from' => '#a3e635', 'dark_to' => '#34d399'],
        ],

        'woodland' => [
            'label' => 'Woodland',
            'scale' => [50 => '#f4fbf9', 100 => '#e5f5f1', 200 => '#c8eae1', 300 => '#9fdbcc', 400 => '#76cbb6', 500 => '#3b9b83', 600 => '#31816d', 700 => '#276857', 800 => '#1d4e42', 900 => '#112c25', 950 => '#112c25'],
            'accent' => '#31816d',
            'accent_hover' => '#276857',
            'accent_on' => '#ffffff',
            'dark_accent' => '#76cbb6',
            'dark_accent_hover' => '#9fdbcc',
            'dark_accent_on' => '#141413',
            'gradient' => ['from' => '#31816d', 'to' => '#687d36', 'dark_from' => '#76cbb6', 'dark_to' => '#b0c67b'],
        ],

        'rosewood' => [
            'label' => 'Rosewood',
            'scale' => [50 => '#fbf4f6', 100 => '#f5e5e9', 200 => '#eec4ce', 300 => '#e198aa', 400 => '#d56d87', 500 => '#c84163', 600 => '#a6304e', 700 => '#8a2841', 800 => '#6f2034', 900 => '#531827', 950 => '#2f0e16'],
            'accent' => '#a6304e',
            'accent_hover' => '#8a2841',
            'accent_on' => '#ffffff',
            'dark_accent' => '#d56d87',
            'dark_accent_hover' => '#e198aa',
            'dark_accent_on' => '#141413',
            'gradient' => ['from' => '#a6304e', 'to' => '#a94c2d', 'dark_from' => '#d56d87', 'dark_to' => '#d7856a'],
        ],
    ],
];
