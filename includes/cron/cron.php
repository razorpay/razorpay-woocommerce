<?php

add_action(Constants::ONE_CC_ADDRESS_SYNC_CRON_HOOK, Constants::ONE_CC_ADDRESS_SYNC_CRON_EXEC);

function createCron(string $hookName, int $startTime, string $recurrence)
{
    if (wp_next_scheduled($hookName))
    {
        throw new Exception($hookName . " already exists");
    }

    wp_schedule_event($startTime, $recurrence, $hookName);

}

function deleteCron(string $hookName)
{
    $timestamp = wp_next_scheduled( $hookName );
    wp_unschedule_event( $timestamp, $hookName );
}
