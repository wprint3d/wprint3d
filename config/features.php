<?php

/*
 * The array presented below contains a set of feature-flags that'll be toggled
 * as the more advanced features are better tested.
 * 
 * This logic allows for the logical structures to co-exist with the rest of
 * tested modules and lets developers turn them off and on to keep working on
 * them locally.
 */

return [

    'login'     => [ 'remember_me'  => false ],

    'settings'  => [ 'profile'      => false ],

    'terminal'  => [ 'auto_temperature_query' => true ]

];