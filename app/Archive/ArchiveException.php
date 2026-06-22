<?php

namespace App\Archive;

use RuntimeException;

/** Thrown when an archive can't be opened/read/decoded. / アーカイブの読み取り失敗時に送出。 */
final class ArchiveException extends RuntimeException
{
}
