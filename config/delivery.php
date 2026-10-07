<?php

return [
    'attempt_limit' => max(1, (int) env('DELIVERY_ATTEMPT_LIMIT', 3)),
];
