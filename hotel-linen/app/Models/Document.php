<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $fillable = [
        'number','kind','date','supplier_id','doc_external','from_location_id','to_location_id',
        'room_id','laundry_stage','status','created_by','approved_by','note','meta',
    ];
    protected $casts = ['meta' => 'array', 'date' => 'date'];

    public function lines()     { return $this->hasMany(DocumentLine::class); }
    public function supplier()  { return $this->belongsTo(Supplier::class); }
    public function room()      { return $this->belongsTo(Room::class); }
    public function fromLocation() { return $this->belongsTo(Location::class, 'from_location_id'); }
    public function toLocation()   { return $this->belongsTo(Location::class, 'to_location_id'); }
    public function creator()   { return $this->belongsTo(User::class, 'created_by'); }
    public function approver()  { return $this->belongsTo(User::class, 'approved_by'); }
    public function checklists(){ return $this->hasMany(ChecklistResult::class); }
    public function photos()    { return $this->hasMany(Photo::class); }
    public function counts()    { return $this->hasMany(InventoryCount::class); }

    public static function nextNumber(string $kind): string
    {
        $prefix = match ($kind) {
            'receipt' => 'ПН', 'transfer' => 'МП', 'laundry_out' => 'ПО', 'laundry_in' => 'ПИ',
            'write_off' => 'АС', 'inventory' => 'ИН', 'acceptance' => 'АП', 'readiness' => 'ГК',
            default => 'ДО',
        };
        $year = now()->format('Y');
        $seq = self::where('kind', $kind)->whereYear('date', $year)->count() + 1;
        return sprintf('%s-%s-%04d', $prefix, $year, $seq);
    }
}
