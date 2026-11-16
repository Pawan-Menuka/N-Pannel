<?php

namespace App\Policies;

use App\Models\DocumentSequence;

class DocumentSequencePolicy extends ModelPolicy
{
    protected const string Model = DocumentSequence::class;
}
