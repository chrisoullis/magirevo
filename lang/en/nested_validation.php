<?php

return [
    'precision' => 'The resolved amount is smaller than the supported measurement precision. Increase the amount or choose manual scaling.',
    'identity' => 'The recipe component has an invalid line identity.',
    'cycle' => 'This component would create a circular recipe dependency.',
    'depth' => 'This component exceeds the maximum recipe nesting depth.',
    'limit' => 'This recipe exceeds the component expansion limit.',
    'unavailable' => 'The recipe component is unavailable.',
    'revision' => 'The component revision reference is invalid.',
    'basis' => 'Choose a supported quantity basis.',
    'yield' => 'This amount requires a verified compatible batch yield.',
    'servings' => 'The component recipe must have positive base servings.',
    'ingredient' => 'A referenced ingredient is unavailable.',
    'lines' => 'This recipe exceeds the expanded ingredient limit.',
];
