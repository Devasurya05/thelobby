<?php

return [

    // How long a tapped bed stays reserved for one person before it is released automatically.
    // 120 = 2 minutes (the default). For a live demo with friends set HOLD_SECONDS=30 in .env.
    'hold_seconds' => (int) env('HOLD_SECONDS', 120),

    // How often the lobby screen asks the server for fresh bed states (milliseconds).
    'poll_ms' => (int) env('LOBBY_POLL_MS', 2000),

];
