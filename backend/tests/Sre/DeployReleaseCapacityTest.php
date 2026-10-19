<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class DeployReleaseCapacityTest extends TestCase
{
    public function test_capacity_rejects_blocks_or_inodes_even_without_releases_to_prune(): void
    {
        $script = dirname(__DIR__, 2).'/scripts/deploy/check_release_capacity.py';
        $code = <<<'PY'
import importlib.util, json, sys, tempfile
from types import SimpleNamespace
s=importlib.util.spec_from_file_location('capacity',sys.argv[1]);m=importlib.util.module_from_spec(s);s.loader.exec_module(m)
required_bytes,required_inodes=m.requirement(100000,100,50000,50,20000)
assert required_bytes > 2*(100000+50000)+20000
assert required_inodes > 2*(100+50)
restore_bytes,restore_inodes=m.requirement(100000,100,50000,50,20000,1)
assert required_bytes-(100000+50000+64*1024**2+20000) == restore_bytes
assert required_inodes-(100+50+4096) == restore_inodes
with tempfile.TemporaryDirectory() as root:
    m.inventory=lambda _: (0,0)
    sys.argv=['capacity',root,'100000','100','2']
    b,i=m.requirement(100000,100,0,0,0)
    for free_bytes,free_inodes,expected in [(b-1,i,1),(b,i-1,1),(b,i,0),(b*2,i*2,0)]:
        m.os.statvfs=lambda _, b=free_bytes,i=free_inodes: SimpleNamespace(f_bavail=b,f_frsize=1,f_favail=i)
        assert m.main()==expected
print('capacity_cases_pass')
PY;
        $process = new Process(['python3', '-c', $code, $script], null, ['PYTHONDONTWRITEBYTECODE' => '1']);
        $process->mustRun();
        self::assertStringContainsString('capacity_cases_pass', $process->getOutput());
    }
}
