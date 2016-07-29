<?php

/*
 * This file is part of the puli/cli package.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Puli\Cli\Tests\Handler;

use PHPUnit_Framework_MockObject_MockObject;
use Puli\Cli\Handler\PackageCommandHandler;
use Puli\Manager\Api\Context\ProjectContext;
use Puli\Manager\Api\Environment;
use Puli\Manager\Api\Module\InstallInfo;
use Puli\Manager\Api\Module\Module;
use Puli\Manager\Api\Module\ModuleFile;
use Puli\Manager\Api\Module\ModuleList;
use Puli\Manager\Api\Module\ModuleManager;
use Puli\Manager\Api\Module\ModuleState;
use Puli\Manager\Api\Module\RootModule;
use Puli\Manager\Api\Module\RootModuleFile;
use RuntimeException;
use Webmozart\Console\Api\Command\Command;
use Webmozart\Console\Args\StringArgs;
use Webmozart\Expression\Expr;
use Webmozart\Expression\Expression;
use Webmozart\PathUtil\Path;

/**
 * @since  1.0
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class PackageCommandHandlerTest extends AbstractCommandHandlerTest
{
    /**
     * @var Command
     */
    private static $listCommand;

    /**
     * @var Command
     */
    private static $installCommand;

    /**
     * @var Command
     */
    private static $renameCommand;

    /**
     * @var Command
     */
    private static $deleteCommand;

    /**
     * @var Command
     */
    private static $cleanCommand;

    /**
     * @var PHPUnit_Framework_MockObject_MockObject|ProjectContext
     */
    private $context;

    /**
     * @var PHPUnit_Framework_MockObject_MockObject|ModuleManager
     */
    private $packageManager;

    /**
     * @var PackageCommandHandler
     */
    private $handler;

    /**
     * @var string
     */
    private $wd;

    /**
     * @var string
     */
    private $previousWd;

    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();

        self::$listCommand = self::$application->getCommand('package')->getSubCommand('list');
        self::$installCommand = self::$application->getCommand('package')->getSubCommand('install');
        self::$renameCommand = self::$application->getCommand('package')->getSubCommand('rename');
        self::$deleteCommand = self::$application->getCommand('package')->getSubCommand('delete');
        self::$cleanCommand = self::$application->getCommand('package')->getSubCommand('clean');
    }

    protected function setUp()
    {
        parent::setUp();

        $this->context = $this->getMockBuilder('Puli\Manager\Api\Context\ProjectContext')
            ->disableOriginalConstructor()
            ->getMock();
        $this->packageManager = $this->getMock('Puli\Manager\Api\Module\ModuleManager');
        $this->handler = new PackageCommandHandler($this->packageManager);

        $this->context->expects($this->any())
            ->method('getRootDirectory')
            ->willReturn(__DIR__.'/Fixtures/root');

        $this->packageManager->expects($this->any())
            ->method('getContext')
            ->willReturn($this->context);

        $installInfo1 = new InstallInfo('vendor/package1', 'packages/package1');
        $installInfo2 = new InstallInfo('vendor/package2', 'packages/package2');
        $installInfo3 = new InstallInfo('vendor/package3', 'packages/package3');
        $installInfo4 = new InstallInfo('vendor/package4', 'packages/package4');
        $installInfo5 = new InstallInfo('vendor/package5', 'packages/package5');

        $installInfo1->setInstallerName('spock');
        $installInfo2->setInstallerName('spock');
        $installInfo3->setInstallerName('kirk');
        $installInfo4->setInstallerName('spock');
        $installInfo5->setInstallerName('spock');
        $installInfo5->setEnvironment(Environment::DEV);

        $rootModule = new RootModule(new RootModuleFile('vendor/root'), __DIR__.'/Fixtures/root');
        $package1 = new Module(new ModuleFile('vendor/package1'), __DIR__.'/Fixtures/root/packages/package1', $installInfo1);
        $package2 = new Module(new ModuleFile('vendor/package2'), __DIR__.'/Fixtures/root/packages/package2', $installInfo2);
        $package3 = new Module(new ModuleFile('vendor/package3'), __DIR__.'/Fixtures/root/packages/package3', $installInfo3);
        $package4 = new Module(null, __DIR__.'/Fixtures/root/packages/package4', $installInfo4, array(new RuntimeException('Load error')));
        $package5 = new Module(new ModuleFile('vendor/package5'), __DIR__.'/Fixtures/root/packages/package5', $installInfo5);

        $this->packageManager->expects($this->any())
            ->method('findModules')
            ->willReturnCallback($this->returnFromMap(array(
                array($this->all(), new ModuleList(array($rootModule, $package1, $package2, $package3, $package4, $package5))),
                array($this->env(array(Environment::PROD, Environment::DEV)), new ModuleList(array($rootModule, $package1, $package2, $package3, $package4, $package5))),
                array($this->env(array(Environment::PROD)), new ModuleList(array($rootModule, $package1, $package2, $package3, $package4))),
                array($this->env(array(Environment::DEV)), new ModuleList(array($package5))),
                array($this->installer('spock'), new ModuleList(array($package1, $package2, $package4, $package5))),
                array($this->state(ModuleState::ENABLED), new ModuleList(array($rootModule, $package1, $package2, $package5))),
                array($this->state(ModuleState::NOT_FOUND), new ModuleList(array($package3))),
                array($this->state(ModuleState::NOT_LOADABLE), new ModuleList(array($package4))),
                array($this->states(array(ModuleState::ENABLED, ModuleState::NOT_FOUND)), new ModuleList(array($rootModule, $package1, $package2, $package3, $package5))),
                array($this->installerAndState('spock', ModuleState::ENABLED), new ModuleList(array($package1, $package2, $package5))),
                array($this->installerAndState('spock', ModuleState::NOT_FOUND), new ModuleList(array())),
                array($this->installerAndState('spock', ModuleState::NOT_LOADABLE), new ModuleList(array($package4))),
            )));

        $this->previousWd = getcwd();
        $this->wd = Path::normalize(__DIR__);

        chdir($this->wd);
    }

    protected function tearDown()
    {
        chdir($this->previousWd);
    }

    public function testListPackages()
    {
        $args = self::$listCommand->parseArgs(new StringArgs(''));

        $statusCode = $this->handler->handleList($args, $this->io);

        $expected = <<<'EOF'
The following packages are currently enabled:

    Package Name     Installer  Env   Install Path
    vendor/package1  spock      prod  packages/package1
    vendor/package2  spock      prod  packages/package2
    vendor/package5  spock      dev   packages/package5
    vendor/root                 prod  .

The following packages could not be found:
 (use "puli package --clean" to remove)

    Package Name     Installer  Env   Install Path
    vendor/package3  kirk       prod  packages/package3

The following packages could not be loaded:

    Package Name     Error
    vendor/package4  RuntimeException: Load error


EOF;

        $this->assertSame(0, $statusCode);
        $this->assertSame($expected, $this->io->fetchOutput());
        $this->assertEmpty($this->io->fetchErrors());
    }

    public function testListPackagesByInstaller()
    {
        $args = self::$listCommand->parseArgs(new StringArgs('--installer spock'));

        $statusCode = $this->handler->handleList($args, $this->io);

        $expected = <<<'EOF'
The following packages are currently enabled:

    Package Name     Installer  Env   Install Path
    vendor/package1  spock      prod  packages/package1
    vendor/package2  spock      prod  packages/package2
    vendor/package5  spock      dev   packages/package5

The following packages could not be loaded:

    Package Name     Error
    vendor/package4  RuntimeException: Load error


EOF;

        $this->assertSame(0, $statusCode);
        $this->assertSame($expected, $this->io->fetchOutput());
        $this->assertEmpty($this->io->fetchErrors());
    }

    public function testListEnabledPackages()
    {
        $args = self::$listCommand->parseArgs(new StringArgs('--enabled'));

        $statusCode = $this->handler->handleList($args, $this->io);

        $expected = <<<'EOF'
Package Name     Installer  Env   Install Path
vendor/package1  spock      prod  packages/package1
vendor/package2  spock      prod  packages/package2
vendor/package5  spock      dev   packages/package5
vendor/root                 prod  .

EOF;

        $this->assertSame(0, $statusCode);
        $this->assertSame($expected, $this->io->fetchOutput());
        $this->assertEmpty($this->io->fetchErrors());
    }

    public function testListNotFoundPackages()
    {
        $args = self::$listCommand->parseArgs(new StringArgs('--not-found'));

        $statusCode = $this->handler->handleList($args, $this->io);

        $expected = <<<'EOF'
Package Name     Installer  Env   Install Path
vendor/package3  kirk       prod  packages/package3

EOF;

        $this->assertSame(0, $statusCode);
        $this->assertSame($expected, $this->io->fetchOutput());
        $this->assertEmpty($this->io->fetchErrors());
    }

    public function testListNotLoadablePackages()
    {
        $args = self::$listCommand->parseArgs(new StringArgs('--not-loadable'));

        $statusCode = $this->handler->handleList($args, $this->io);

        $expected = <<<'EOF'
Package Name     Error
vendor/package4  RuntimeException: Load error

EOF;

        $this->assertSame(0, $statusCode);
        $this->assertSame($expected, $this->io->fetchOutput());
        $this->assertEmpty($this->io->fetchErrors());
    }

    public function testListEnabledAndNotFoundPackages()
    {
        $args = self::$listCommand->parseArgs(new StringArgs('--enabled --not-found'));

        $statusCode = $this->handler->handleList($args, $this->io);

        $expected = <<<'EOF'
The following packages are currently enabled:

    Package Name     Installer  Env   Install Path
    vendor/package1  spock      prod  packages/package1
    vendor/package2  spock      prod  packages/package2
    vendor/package5  spock      dev   packages/package5
    vendor/root                 prod  .

The following packages could not be found:
 (use "puli package --clean" to remove)

    Package Name     Installer  Env   Install Path
    vendor/package3  kirk       prod  packages/package3


EOF;

        $this->assertSame(0, $statusCode);
        $this->assertSame($expected, $this->io->fetchOutput());
        $this->assertEmpty($this->io->fetchErrors());
    }

    public function testListEnabledPackagesByInstaller()
    {
        $args = self::$listCommand->parseArgs(new StringArgs('--enabled --installer spock'));

        $statusCode = $this->handler->handleList($args, $this->io);

        $expected = <<<'EOF'
Package Name     Installer  Env   Install Path
vendor/package1  spock      prod  packages/package1
vendor/package2  spock      prod  packages/package2
vendor/package5  spock      dev   packages/package5

EOF;

        $this->assertSame(0, $statusCode);
        $this->assertSame($expected, $this->io->fetchOutput());
        $this->assertEmpty($this->io->fetchErrors());
    }

    public function testListDevPackages()
    {
        $args = self::$listCommand->parseArgs(new StringArgs('--dev'));

        $statusCode = $this->handler->handleList($args, $this->io);

        $expected = <<<'EOF'
The following packages are currently enabled:

    Package Name     Installer  Env  Install Path
    vendor/package5  spock      dev  packages/package5


EOF;

        $this->assertSame(0, $statusCode);
        $this->assertSame($expected, $this->io->fetchOutput());
        $this->assertEmpty($this->io->fetchErrors());
    }

    public function testListNoDevPackages()
    {
        $args = self::$listCommand->parseArgs(new StringArgs('--prod'));

        $statusCode = $this->handler->handleList($args, $this->io);

        $expected = <<<'EOF'
The following packages are currently enabled:

    Package Name     Installer  Env   Install Path
    vendor/package1  spock      prod  packages/package1
    vendor/package2  spock      prod  packages/package2
    vendor/root                 prod  .

The following packages could not be found:
 (use "puli package --clean" to remove)

    Package Name     Installer  Env   Install Path
    vendor/package3  kirk       prod  packages/package3

The following packages could not be loaded:

    Package Name     Error
    vendor/package4  RuntimeException: Load error


EOF;

        $this->assertSame(0, $statusCode);
        $this->assertSame($expected, $this->io->fetchOutput());
        $this->assertEmpty($this->io->fetchErrors());
    }

    public function testListDevAndNoDevPackages()
    {
        // Same as if passing none of the too
        // Does not make much sense but could occur if building the
        // "package list" command dynamically
        $args = self::$listCommand->parseArgs(new StringArgs('--dev --prod'));

        $statusCode = $this->handler->handleList($args, $this->io);

        $expected = <<<'EOF'
The following packages are currently enabled:

    Package Name     Installer  Env   Install Path
    vendor/package1  spock      prod  packages/package1
    vendor/package2  spock      prod  packages/package2
    vendor/package5  spock      dev   packages/package5
    vendor/root                 prod  .

The following packages could not be found:
 (use "puli package --clean" to remove)

    Package Name     Installer  Env   Install Path
    vendor/package3  kirk       prod  packages/package3

The following packages could not be loaded:

    Package Name     Error
    vendor/package4  RuntimeException: Load error


EOF;

        $this->assertSame(0, $statusCode);
        $this->assertSame($expected, $this->io->fetchOutput());
        $this->assertEmpty($this->io->fetchErrors());
    }

    public function testListPackagesWithFormat()
    {
        $args = self::$listCommand->parseArgs(new StringArgs('--format %name%:%installer%:%install_path%:%state%:%env%'));

        $rootDir = $this->context->getRootDirectory();
        $statusCode = $this->handler->handleList($args, $this->io);

        $expected = <<<EOF
vendor/root::$rootDir:enabled:prod
vendor/package1:spock:$rootDir/packages/package1:enabled:prod
vendor/package2:spock:$rootDir/packages/package2:enabled:prod
vendor/package3:kirk:$rootDir/packages/package3:not-found:prod
vendor/package4:spock:$rootDir/packages/package4:not-loadable:prod
vendor/package5:spock:$rootDir/packages/package5:enabled:dev

EOF;

        $this->assertSame(0, $statusCode);
        $this->assertSame($expected, $this->io->fetchOutput());
        $this->assertEmpty($this->io->fetchErrors());
    }

    public function testInstallModuleWithRelativePath()
    {
        $args = self::$installCommand->parseArgs(new StringArgs('packages/package1'));

        $this->packageManager->expects($this->once())
            ->method('installModule')
            ->with($this->wd.'/packages/package1', null, InstallInfo::DEFAULT_INSTALLER_NAME, Environment::PROD);

        $this->assertSame(0, $this->handler->handleInstall($args));
    }

    public function testInstallModuleWithAbsolutePath()
    {
        $args = self::$installCommand->parseArgs(new StringArgs('/packages/package1'));

        $this->packageManager->expects($this->once())
            ->method('installModule')
            ->with('/packages/package1', null, InstallInfo::DEFAULT_INSTALLER_NAME, Environment::PROD);

        $this->assertSame(0, $this->handler->handleInstall($args));
    }

    public function testInstallModuleWithCustomName()
    {
        $args = self::$installCommand->parseArgs(new StringArgs('/packages/package1 custom/package1'));

        $this->packageManager->expects($this->once())
            ->method('installModule')
            ->with('/packages/package1', 'custom/package1', InstallInfo::DEFAULT_INSTALLER_NAME, Environment::PROD);

        $this->assertSame(0, $this->handler->handleInstall($args));
    }

    public function testInstallModuleWithCustomInstaller()
    {
        $args = self::$installCommand->parseArgs(new StringArgs('--installer kirk /packages/package1'));

        $this->packageManager->expects($this->once())
            ->method('installModule')
            ->with('/packages/package1', null, 'kirk', Environment::PROD);

        $this->assertSame(0, $this->handler->handleInstall($args));
    }

    public function testInstallDevModule()
    {
        $args = self::$installCommand->parseArgs(new StringArgs('--dev packages/package1'));

        $this->packageManager->expects($this->once())
            ->method('installModule')
            ->with($this->wd.'/packages/package1', null, InstallInfo::DEFAULT_INSTALLER_NAME, Environment::DEV);

        $this->assertSame(0, $this->handler->handleInstall($args));
    }

    public function testRenameModule()
    {
        $args = self::$renameCommand->parseArgs(new StringArgs('vendor/package1 vendor/new'));

        $this->packageManager->expects($this->once())
            ->method('renameModule')
            ->with('vendor/package1', 'vendor/new');

        $this->assertSame(0, $this->handler->handleRename($args));
    }

    public function testDeleteModule()
    {
        $args = self::$deleteCommand->parseArgs(new StringArgs('vendor/package1'));

        $this->packageManager->expects($this->once())
            ->method('hasModule')
            ->with('vendor/package1')
            ->willReturn(true);

        $this->packageManager->expects($this->once())
            ->method('removeModule')
            ->with('vendor/package1');

        $this->assertSame(0, $this->handler->handleDelete($args));
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage The package "vendor/package1" is not installed.
     */
    public function testDeleteModuleFailsIfNotFound()
    {
        $args = self::$deleteCommand->parseArgs(new StringArgs('vendor/package1'));

        $this->packageManager->expects($this->once())
            ->method('hasModule')
            ->with('vendor/package1')
            ->willReturn(false);

        $this->packageManager->expects($this->never())
            ->method('removeModule')
            ->with('vendor/package1');

        $this->handler->handleDelete($args);
    }

    public function testCleanModules()
    {
        $args = self::$cleanCommand->parseArgs(new StringArgs(''));

        // The not-found package
        $this->packageManager->expects($this->once())
            ->method('removeModule')
            ->with('vendor/package3');

        $expected = <<<'EOF'
Removing vendor/package3

EOF;

        $this->assertSame(0, $this->handler->handleClean($args, $this->io));
        $this->assertSame($expected, $this->io->fetchOutput());
        $this->assertEmpty($this->io->fetchErrors());
    }

    private function all()
    {
        return Expr::true();
    }

    private function env(array $envs)
    {
        return Expr::method('getInstallInfo', Expr::method('getEnvironment', Expr::in($envs)));
    }

    private function state($state)
    {
        return Expr::method('getState', Expr::same($state));
    }

    private function states(array $states)
    {
        return Expr::method('getState', Expr::in($states));
    }

    private function installer($installer)
    {
        return Expr::method('getInstallInfo', Expr::method('getInstallerName', Expr::same($installer)));
    }

    private function installerAndState($installer, $state)
    {
        return Expr::method('getInstallInfo', Expr::method('getInstallerName', Expr::same($installer)))
            ->andMethod('getState', Expr::same($state));
    }

    private function returnFromMap(array $map)
    {
        return function (Expression $expr) use ($map) {
            foreach ($map as $arguments) {
                // Cannot use willReturnMap(), which uses ===
                if ($expr->equivalentTo($arguments[0])) {
                    return $arguments[1];
                }
            }

            return null;
        };
    }
}
