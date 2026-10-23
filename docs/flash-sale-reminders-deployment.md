# Flash sale reminder scheduling

Flash sale reminder notifications are written synchronously to the existing `notifications` table. No queue worker is required for this feature.

The Laravel scheduler must run every minute on the application host:

```cron
* * * * * cd /path/to/likhae-marketplace && php artisan schedule:run >> /dev/null 2>&1
```

The scheduled `likhae:send-flash-sale-reminders` command checks due reminders in the `Asia/Manila` timezone. It records the reminder and notification in one database transaction, so scheduler retries do not send a second notification. Keep the application's timezone and database clock synchronized with the configured sale schedule.
