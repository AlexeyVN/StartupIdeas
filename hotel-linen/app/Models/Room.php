<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    // Порядок перехода статусов номера (4.6)
    public const FLOW = ['DIRTY' => ['CLEANING'], 'CLEANING' => ['INSPECTION'], 'INSPECTION' => ['READY', 'DIRTY']];

    protected $fillable = [
        'building_id','floor','number','category','capacity','status',
        'linen_problem','broken','template_id','maid_id','note',
    ];
    protected $casts = ['linen_problem' => 'boolean', 'broken' => 'boolean'];

    public function building() { return $this->belongsTo(Building::class); }
    public function template() { return $this->belongsTo(Template::class); }
    public function maid()     { return $this->belongsTo(User::class, 'maid_id'); }
    public function stocks()   { return $this->hasMany(RoomStock::class); }
    public function assets()   { return $this->hasMany(Asset::class); }
    public function issues()   { return $this->hasMany(Issue::class); }

    public function label(): string
    {
        return sprintf('№%s (%s, эт.%d)', $this->number, $this->building?->name ?? '?', $this->floor);
    }

    public function canTransitionTo(string $to): bool
    {
        return in_array($to, self::FLOW[$this->status] ?? [], true);
    }
}
