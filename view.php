<?php
/**
 * Simple HTML template for displaying the generated SVG.
 *
 * Copyright 2016 by Dominik "Fenrikur" Schöner <nosecounter@fenrikur.de>
 */

$output = <<< EOF
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Nosecounter {$nosecounterData->year}</title>
    <style type="text/css">
        @keyframes appear {
            from {opacity: 0; max-height: 0;}
            to {opacity: 100; max-height: 90ex;}
        }

        body {
            font-family: sans-serif;
        }

        label {
            font-size: 140%;
            font-weight: bold;
        }

        input[type=checkbox] {
            display: none;
        }

        input[type=checkbox] ~ label::before {
            content: "+";
            padding-right: 1ex;
            color: #777;
        }

        input[type=checkbox]:checked ~ label::before {
            content: "–";
            padding-right: 1ex;
            color: #777;
        }

        input[type=checkbox] ~ div {
            display: none;
        }

        input[type=checkbox]:checked ~ div {
            display: block;
            animation-name: appear;
            animation-timing-function: ease-in-out;
            animation-duration: 2s;
        }

        #nosecounter-statusbar {
            font-weight: bold;
            border: 1px solid #222;
            background-color: #eee;
            padding: 1ex;
            margin: 1em 0;
            display: table;
        }
    </style>
</head>
<body>
<h1>Nosecounter {$nosecounterData->year}</h1>

<div id="nosecounter-statusbar" style="">
    {$nosecounterData->statusbar}
</div>

<div>
<input type="checkbox" id="nosecounter-regs" /><label for="nosecounter-regs">Registrations per {$nosecounterData->registrationsInterval}</label>
<div><embed src="{$nosecounterData->registrations}" type="image/svg+xml" style="width: 100%" /></div>
</div>


<div>
<input type="checkbox" id="nosecounter-status" /><label for="nosecounter-status">Attendance by Status</label>
<div><embed src="{$nosecounterData->status}" type="image/svg+xml" style="width: 100%" /></div>
</div>

<div>
<input type="checkbox" id="nosecounter-country" /><label for="nosecounter-country">Attendance by Country</label>
<div><embed src="{$nosecounterData->country}" type="image/svg+xml" style="width: 100%" /></div>
</div>

<div>
<input type="checkbox" id="nosecounter-country-cmp" /><label for="nosecounter-country-cmp">Attendance by Country (Comparison)</label>
<div><embed src="{$nosecounterData->countryComparison}" type="image/svg+xml" style="width: 100%" /></div>
</div>

<div>
<input type="checkbox" id="nosecounter-gender" /><label for="nosecounter-gender">Attendance by Gender</label>
<div><embed src="{$nosecounterData->gender}" type="image/svg+xml" style="width: 100%" /></div>
</div>

<div>
<input type="checkbox" id="nosecounter-gender-cmp" /><label for="nosecounter-gender-cmp">Attendance by Gender (Comparison)</label>
<div><embed src="{$nosecounterData->genderComparison}" type="image/svg+xml" style="width: 100%" /></div>
</div>

<div>
<input type="checkbox" id="nosecounter-sponsors" /><label for="nosecounter-sponsors">Sponsors</label>
<div><embed src="{$nosecounterData->sponsors}" type="image/svg+xml" style="width: 100%" /></div>
</div>

<div>
<input type="checkbox" id="nosecounter-sponsors-cmp" /><label for="nosecounter-sponsors-cmp">Sponsors (Comparison)</label>
<div><embed src="{$nosecounterData->sponsorsComparison}" type="image/svg+xml" style="width: 100%" /></div>
</div>

<div>
<input type="checkbox" id="nosecounter-age" /><label for="nosecounter-age">Age Distribution</label>
<div><embed src="{$nosecounterData->age}" type="image/svg+xml" style="width: 100%" /></div>
</div>

<div>
<input type="checkbox" id="nosecounter-age-cmp" /><label for="nosecounter-age-cmp">Age Distribution (Comparison)</label>
<div><embed src="{$nosecounterData->ageComparison}" type="image/svg+xml" style="width: 100%" /></div>
</div>

<div>
<input type="checkbox" id="nosecounter-demographics" /><label for="nosecounter-demographics">Demographics (Comparison)</label>
<div><embed src="{$nosecounterData->demographics}" type="image/svg+xml" style="width: 100%" /></div>
</div>

<div>
<input type="checkbox" id="nosecounter-shirts" /><label for="nosecounter-shirts">T-Shirt Sizes (Comparison)</label>
<div><embed src="{$nosecounterData->shirts}" type="image/svg+xml" style="width: 100%" /></div>
</div>

<pre><p>Generated in {$nosecounterData->generatedIn} at {$nosecounterData->generatedAt->format('Y-m-d H:i:s P')}.</p></pre>

</body>
</html>
EOF;
