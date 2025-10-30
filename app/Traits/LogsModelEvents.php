<?php

namespace App\Traits;
use App\Models\QueryLog;
use Illuminate\Support\Facades\Auth;
use App\Jobs\LogQueryJob;
trait LogsModelEvents
{
    public static function bootLogsModelEvents()
    {
        static::created(fn($model) => self::logModel('insert', $model));
        static::updated(fn($model) => self::logModel('update', $model));
        static::deleted(fn($model) => self::logModel('delete', $model));
    }

    protected static function logModel($action, $model)
    {
       $userId = Auth::check() ? Auth::id() : null;

        $data = [
            'sql' => "{$action} on " . get_class($model),
            'bindings' => json_encode([
                'attributes' => $action === 'delete' ? $model->getOriginal() : $model->getAttributes(),
                'changes' => $action === 'update' ? $model->getChanges() : null,
                'model_id' => $model->id ?? null,
            ]),
            'time' => null,
            'user_id' => $userId,
        ];

        LogQueryJob::dispatch($data);
    }
}
