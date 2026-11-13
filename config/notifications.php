<?php

return [

    /*
    | The queue the notification jobs are pushed to. It is its own queue so a burst of
    | notifications never waits behind (or holds up) other jobs. A worker has to listen
    | to it:  php artisan queue:work --queue=notifications,default
    | (`composer dev` already does.)
    */
    'queue' => env('NOTIFICATIONS_QUEUE', 'notifications'),

];
