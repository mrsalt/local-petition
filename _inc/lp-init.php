<?php

function lp_handle_init()
{
    if (!session_id()) {
        session_start();
    }
}
