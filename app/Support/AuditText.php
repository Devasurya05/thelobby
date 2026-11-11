<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Support\Collection;

/**
 * Turns raw audit rows into readable text for the organiser's Audit page.
 * The database keeps the stable machine words (`grab_rejected`); only this class makes them friendly.
 * Everything returned is plain text: the Blade view escapes it, so a member named <script> is harmless.
 */
class AuditText
{
    private const LABELS = [
        'trip_created' => 'Trip created',
        'member_joined' => 'Joined the trip',
        'bed_held' => 'Held a bed',
        'bed_confirmed' => 'Confirmed a bed',
        'bed_released' => 'Bed released',
        'bed_given_up' => 'Gave up a confirmed bed',
        'give_up_rejected' => 'Give-up refused',
        'hold_expired' => 'Hold ran out',
        'grab_rejected' => 'Grab refused',
        'confirm_rejected' => 'Confirm refused',
        'release_rejected' => 'Release refused',
        'action_refused' => 'Action refused',
        'rate_limited' => 'Slowed down (too many taps)',
        'member_removed' => 'Member removed',
        'role_changed' => 'Role changed',
        'file_uploaded' => 'Uploaded a file',
        'file_viewed' => 'Opened a file',
        'file_deleted' => 'Deleted a file',
        'trip_ended' => 'Trip ended',
        'vault_purged' => 'Vault emptied (all files deleted)',
    ];

    public static function label(string $action): string
    {
        return self::LABELS[$action] ?? $action;
    }

    /**
     * What the entry is about, e.g. "Room 1 · Window". $beds and $members are lookup tables
     * (id => model) built once by the controller, so this does no database queries per row.
     */
    public static function target(AuditLog $log, Collection $beds, Collection $members): string
    {
        if ($log->target_id === null) {
            return '';
        }

        return match ($log->target_type) {
            'bed' => ($bed = $beds->get($log->target_id)) ? $bed->room->name.' · '.$bed->label : 'bed #'.$log->target_id,
            'member' => $members->get($log->target_id)?->name ?? 'person #'.$log->target_id,
            default => '',
        };
    }

    /** "reason: held by someone else, hold seconds: 30" from the JSON details. Scalars only, kept short. */
    public static function details(AuditLog $log): string
    {
        $parts = [];

        foreach ((array) $log->details as $key => $value) {
            if ($value === null || is_array($value)) {
                continue;
            }
            $parts[] = str_replace('_', ' ', (string) $key).': '.str_replace('_', ' ', is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value);
        }

        return mb_substr(implode(', ', $parts), 0, 300);
    }
}
