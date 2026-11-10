<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LeadEmailAttachment extends Model
{
    public const UPDATED_AT = null;

    public const DISK = 'local';

    /** All the files on one email together: Gmail and Outlook refuse anything much bigger once it's encoded */
    public const MAX_TOTAL = 18 * 1024 * 1024;

    public const MAX_FILES = 10;

    /** Files mail providers bounce, or that only ever arrive as malware */
    public const BLOCKED = ['exe', 'bat', 'cmd', 'com', 'scr', 'msi', 'msp', 'pif', 'vbs', 'vbe', 'js', 'jse', 'wsf', 'wsh', 'ps1', 'jar', 'cpl', 'hta', 'lnk', 'reg', 'dll', 'apk', 'app'];

    /** Shown in the browser rather than downloaded: formats that can't run script on this site */
    private const VIEWABLE = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['size' => 'integer', 'created_at' => 'datetime'];
    }

    public function email(): BelongsTo
    {
        return $this->belongsTo(LeadEmail::class, 'lead_email_id');
    }

    /** The most one email can carry, also within what this server's PHP accepts in one upload */
    public static function maxBytes(): int
    {
        $limits = [self::MAX_TOTAL, self::iniBytes('upload_max_filesize'), self::iniBytes('post_max_size')];

        return (int) min(array_filter($limits, fn ($n) => $n > 0));
    }

    private static function iniBytes(string $key): int
    {
        $value = trim((string) ini_get($key));
        if ($value === '' || $value === '0') {
            return 0;
        }
        $n = (float) $value;

        return (int) match (strtolower(substr($value, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }

    public static function blocked(string $name): bool
    {
        return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::BLOCKED, true);
    }

    /** A file name as people see it, without any path or control characters */
    public static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim(preg_replace('/[\x00-\x1F\x7F"\/\\\\:*?<>|]+/u', ' ', $name) ?? '');

        return Str::limit($name !== '' ? $name : 'attachment', 180, '');
    }

    /** Saves the content under a random name: what a client called a file never becomes a path */
    public static function store(string $content): string
    {
        $path = 'email-attachments/'.now()->format('Y/m').'/'.Str::random(40);
        Storage::disk(self::DISK)->put($path, $content);

        return $path;
    }

    public function viewable(): bool
    {
        return in_array($this->mime, self::VIEWABLE, true);
    }

    public function toRow(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'mime' => $this->mime, 'size' => $this->size, 'url' => "/email-attachments/{$this->id}"];
    }
}
