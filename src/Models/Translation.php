<?php

namespace Waavi\Translation\Models;

use Jenssegers\Mongodb\Eloquent\Model as Eloquent;

class Translation extends Eloquent
{
    /**
     *  Table name in the database.
     *
     *  @var string
     */
    protected $table = 'translator_translations';

    /**
     *  List of variables that can be mass assigned.
     *
     *  @var array
     */
    protected $fillable = ['locale', 'namespace', 'group', 'item', 'text', 'unstable', 'js'];
    protected $hidden   = ['_id'];
    protected $appends  = ['id'];

    /**
     *  Each translation belongs to a language.
     */
    public function language()
    {
        return $this->belongsTo(Language::class, 'locale', 'locale');
    }

    /**
     *  Returns the full translation code for an entry: namespace.group.item.
     *
     *  @return string
     */
    public function getCodeAttribute()
    {
        return $this->namespace === '*' ? "{$this->group}.{$this->item}" : "{$this->namespace}::{$this->group}.{$this->item}";
    }

    /**
     *  Flag this entry as Reviewed.
     */
    public function flagAsReviewed()
    {
        $this->unstable = 0;
    }

    /**
     *  Set the translation to the locked state.
     */
    public function lock()
    {
        $this->locked = 1;
    }

    /**
     *  Check if the translation is locked.
     *
     *  @return bool
     */
    public function isLocked()
    {
        return (boolean) $this->locked;
    }
}
