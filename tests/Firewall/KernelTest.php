<?php 
/*
 * This file is part of the Shieldon package.
 *
 * (c) Terry L. <contact@terryl.in>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Shieldon\Firewall\Tests;

use function Shieldon\Firewall\get_session;

class KernelTest extends \PHPUnit\Framework\TestCase
{
    public function test__construct()
    {
        $properties = [
            'time_unit_quota'        => ['s' => 1, 'm' => 1, 'h' => 1, 'd' => 1],
            'time_reset_limit'       => 1,
            'interval_check_referer' => 1,
            'interval_check_session' => 1,
            'limit_unusual_behavior' => ['cookie' => 1, 'session' => 1, 'referer' => 1],
            'cookie_name'            => 'unittest',
            'cookie_domain'          => 'localhost',
            'display_online_info'    => false,
        ];

        $kernel = new \Shieldon\Firewall\Kernel();
        $kernel->setProperties($properties);

        $reflection = new \ReflectionObject($kernel);
        $t = $reflection->getProperty('properties');
        $t->setAccessible(true);
        $properties = $t->getValue($kernel);

        $this->assertSame($properties['interval_check_session'], 1);
        $this->assertSame($properties['time_reset_limit'], 1);
        $this->assertSame($properties['limit_unusual_behavior'], ['cookie' => 1, 'session' => 1, 'referer' => 1]);
        $this->assertSame($properties['cookie_name'], 'unittest');
        $this->assertSame($properties['cookie_domain'], 'localhost');
        $this->assertSame($properties['display_online_info'], false);
    }

    public function testDetectByFilterFrequency($driver = 'sqlite')
    {
        $kernel = get_testing_shieldon_instance($driver);
        $kernel->driver->rebuild();

        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/70.0.3538.77 Safari/537.36';
        $kernel->add(new \Shieldon\Firewall\Component\Ip());
        $kernel->add(new \Shieldon\Firewall\Component\UserAgent());
        $kernel->add(new \Shieldon\Firewall\Component\TrustedBot());
        $kernel->add(new \Shieldon\Firewall\Component\Rdns());

        $kernel->setChannel('test_shieldon_detect');
        $kernel->driver->rebuild();

        // Test 1.
        $kernel->setIp('141.112.175.1');

        $kernel->setProperty('time_unit_quota', [
            's' => 2,
            'm' => 20, 
            'h' => 60, 
            'd' => 240
        ]);

        $result = [];
        for ($i = 1; $i <= 5; $i++) {
            $result[$i] = $kernel->run();
        }

        $this->assertSame($kernel::RESPONSE_ALLOW, $result[1]);
        $this->assertSame($kernel::RESPONSE_ALLOW, $result[2]);
        $this->assertSame($kernel::RESPONSE_TEMPORARILY_DENY, $result[3]);
        $this->assertSame($kernel::RESPONSE_TEMPORARILY_DENY, $result[4]);
        $this->assertSame($kernel::RESPONSE_TEMPORARILY_DENY, $result[5]);

        // Reset the pageview check for specfic time unit.
        $kernel->setIp('141.112.175.2');
        $this->assertSame($kernel::RESPONSE_ALLOW, $kernel->run());
        sleep(2);
        $this->assertSame($kernel::RESPONSE_ALLOW, $kernel->run());
        $ipDetail = $kernel->driver->get('141.112.175.2', 'filter');

        if ($ipDetail['pageviews_s'] == 0) {
            $this->assertTrue(true);
        } else {
            $this->assertTrue(false);
        }
    }

    public function testDetectByFilterSession($driver = 'sqlite')
    {
        $kernel = get_testing_shieldon_instance($driver);
        $kernel->driver->rebuild();
        $kernel->setIp('141.112.175.2');

        $kernel->setFilters([
            'session'   => true,
            'cookie'    => false,
            'referer'   => false,
            'frequency' => false,
        ]);

        $kernel->setProperty('interval_check_session', 1);
        $kernel->setProperty('limit_unusual_behavior', ['cookie' => 3, 'session' => 3, 'referer' => 3]);

        // Let's get started checking Session.
        for ($i =  0; $i < 5; $i++) {
            $kernel->setIp('140.112.172.255');
            $kernel->limitSession(1000, 9999);
            $reflection = new \ReflectionObject($kernel);
            $methodSetSessionId = $reflection->getMethod('setSessionId');
            $methodSetSessionId->setAccessible(true);
            $methodSetSessionId->invokeArgs($kernel, [md5(date('YmdHis') . mt_rand(2001, 3000))]);
            $results[$i] = $kernel->run();
            sleep(2);
        }

        $this->assertSame($kernel::RESPONSE_ALLOW, $results[0]);
        $this->assertSame($kernel::RESPONSE_ALLOW, $results[1]);
        $this->assertSame($kernel::RESPONSE_ALLOW, $results[2]);
        $this->assertSame($kernel::RESPONSE_ALLOW, $results[3]);
        $this->assertSame($kernel::RESPONSE_TEMPORARILY_DENY, $results[4]);
    }

    public function testDetectByFilterReferer($driver = 'sqlite')
    {
        $kernel = get_testing_shieldon_instance($driver);
        $kernel->driver->rebuild();

        $kernel->setFilters([
            'session'   => false,
            'cookie'    => false,
            'referer'   => true,
            'frequency' => false,
        ]);

        $kernel->setProperty('interval_check_referer', 1);
        $kernel->setProperty('limit_unusual_behavior', ['cookie' => 3, 'session' => 3, 'referer' => 3]);

        for ($i =  0; $i < 5; $i++) {
            $kernel->setIp('140.112.173.1');
            $results[$i] = $kernel->run();
            sleep(2);
        }

        $this->assertSame($kernel::RESPONSE_ALLOW, $results[0]);
        $this->assertSame($kernel::RESPONSE_ALLOW, $results[1]);
        $this->assertSame($kernel::RESPONSE_ALLOW, $results[2]);
        $this->assertSame($kernel::RESPONSE_ALLOW, $results[3]);
        $this->assertSame($kernel::RESPONSE_TEMPORARILY_DENY, $results[4]);
    }

    public function testDetectByFilterCookie($driver = 'sqlite')
    {
        $kernel = get_testing_shieldon_instance($driver);
        $kernel->driver->rebuild();

        $kernel->setFilter('session', false);
        $kernel->setFilter('cookie', true);
        $kernel->setFilter('referer', false);
        $kernel->setFilter('frequency', false);

        $kernel->setProperty('limit_unusual_behavior', ['cookie' => 3, 'session' => 3, 'referer' => 3]);

        for ($i =  0; $i < 5; $i++) {
            $kernel->setIp('140.112.174.8');
            $results[$i] = $kernel->run();
        }

        $this->assertSame($kernel::RESPONSE_ALLOW, $results[0]);
        $this->assertSame($kernel::RESPONSE_ALLOW, $results[1]);
        $this->assertSame($kernel::RESPONSE_ALLOW, $results[2]);
        $this->assertSame($kernel::RESPONSE_ALLOW, $results[3]);
        $this->assertSame($kernel::RESPONSE_TEMPORARILY_DENY, $results[4]);

        $kernel->setProperty('cookie_name', 'unittest');
        $_COOKIE['unittest'] = 1;
        reload_request();

        for ($i =  0; $i < 10; $i++) {
            $kernel->setIp('140.112.175.10');
            $results[$i] = $kernel->run();

            if ($i >= 5) {
                $_COOKIE['unittest'] = 2;
                reload_request();
            }
        }

        $this->assertSame($kernel::RESPONSE_ALLOW, $results[0]);
        $this->assertSame($kernel::RESPONSE_ALLOW, $results[1]);
        $this->assertSame($kernel::RESPONSE_ALLOW, $results[2]);
        $this->assertSame($kernel::RESPONSE_ALLOW, $results[3]);
        $this->assertSame($kernel::RESPONSE_ALLOW, $results[4]);
        $this->assertSame($kernel::RESPONSE_ALLOW, $results[5]);
        $this->assertSame($kernel::RESPONSE_ALLOW, $results[6]);
        $this->assertSame($kernel::RESPONSE_ALLOW, $results[7]);
        $this->assertSame($kernel::RESPONSE_TEMPORARILY_DENY, $results[8]);
        $this->assertSame($kernel::RESPONSE_TEMPORARILY_DENY, $results[9]);
    }

    public function testResetFilterFlagChecks($driver = 'sqlite')
    {
        $kernel = get_testing_shieldon_instance($driver);

        $kernel->setFilters([
            'session'   => false,
            'cookie'    => false,
            'referer'   => true,
            'frequency' => false,
        ]);

        $kernel->setProperty('interval_check_referer', 1);
        $kernel->setProperty('time_reset_limit', 1);
        $kernel->setProperty('limit_unusual_behavior', ['cookie' => 3, 'session' => 3, 'referer' => 3]);

        $kernel->setIp('140.112.173.11');
        $this->assertSame($kernel::RESPONSE_ALLOW, $kernel->run());
        sleep(2);
        $this->assertSame($kernel::RESPONSE_ALLOW, $kernel->run());
        $ipDetail = $kernel->driver->get('140.112.173.11', 'filter');
        $this->assertEquals($ipDetail['flag_empty_referer'], 1);
        sleep(2);
        $this->assertSame($kernel::RESPONSE_ALLOW, $kernel->run());
        $ipDetail = $kernel->driver->get('140.112.173.11', 'filter');
        $this->assertEquals($ipDetail['flag_empty_referer'], 0);
    }

    public function testAction($driver = 'sqlite')
    {
        // Test 1. Check temporaily denying.

        $kernel = get_testing_shieldon_instance($driver);

        $kernel->add(new \Shieldon\Firewall\Log\ActionLogger(BOOTSTRAP_DIR . '/../tmp/shieldon'));

        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/70.0.3538.77 Safari/537.36';
        $kernel->add(new \Shieldon\Firewall\Component\Ip());
        $kernel->add(new \Shieldon\Firewall\Component\UserAgent());
        $kernel->add(new \Shieldon\Firewall\Component\TrustedBot());
        $kernel->add(new \Shieldon\Firewall\Component\Rdns());

        $kernel->setChannel('test_shieldon_detect');
        $kernel->driver->rebuild();

        $reflection = new \ReflectionObject($kernel);
        $method = $reflection->getMethod('action');
        $method->setAccessible(true);
        $method->invokeArgs($kernel, [
            $kernel::ACTION_TEMPORARILY_DENY, $kernel::REASON_REACHED_LIMIT_MINUTE, '140.112.172.11'
        ]);

        $kernel->setIp('140.112.172.11');
        $result = $kernel->run();
        $this->assertSame($kernel::RESPONSE_TEMPORARILY_DENY, $result);

        // Test 2. Check unbaning.

        $method->invokeArgs($kernel, [
            $kernel::ACTION_UNBAN, $kernel::REASON_MANUAL_BAN, '140.112.172.11'
        ]);

        $result = $kernel->run();
        $this->assertSame($kernel::RESPONSE_ALLOW, $result);
    }

    public function testNoComponentAndFilters()
    {
        $kernel = get_testing_shieldon_instance();
        $kernel->setChannel('test_shieldon_detect');
        $kernel->setIp('39.27.1.1');
        $kernel->disableFilters();
        $result = $kernel->run();

        $this->assertSame($kernel::RESPONSE_ALLOW, $result);
    }

    public function testGetComponent()
    {
        $kernel = new \Shieldon\Firewall\Kernel();
        $kernel->add(new \Shieldon\Firewall\Component\Ip());

        $reflection = new \ReflectionObject($kernel);
        $method = $reflection->getMethod('getComponent');
        $method->setAccessible(true);
        $result = $method->invokeArgs($kernel, ['Ip']);

        if ($result instanceof \Shieldon\Firewall\Component\ComponentProvider) {
            $this->assertTrue(true);
        } else {
            $this->assertTrue(false);
        }
    }

    public function testSessionHandler($driver = 'sqlite')
    {
        $kernel = get_testing_shieldon_instance($driver);

        $kernel->setChannel('test_shieldon_session');

        $_limit = 4;
        $kernel->limitSession($_limit, 300);
        $kernel->driver->rebuild();

        $reflection = new \ReflectionObject($kernel);
        $methodSessionHandler = $reflection->getMethod('sessionHandler');
        $methodSessionHandler->setAccessible(true);

        // The first visitor.
        $kernel->setIp('140.112.172.11');
        $methodSetSessionId = $reflection->getMethod('setSessionId');
        $methodSetSessionId->setAccessible(true);

        $sessionId = md5(date('YmdHis') . mt_rand(1, 999999));
        $methodSetSessionId->invokeArgs($kernel, [$sessionId]);
        $kernel->run();

        // Test.
        $testSessionId = get_session()->get('id');

        $this->assertSame($sessionId, $testSessionId);

        $sessionHandlerResult = $methodSessionHandler->invokeArgs($kernel, [$kernel::RESPONSE_ALLOW]);

        $this->assertSame($sessionHandlerResult, $kernel::RESPONSE_ALLOW);

        $t = $reflection->getProperty('sessionStatus');
        $t->setAccessible(true);
        $sessionStatus = $t->getValue($kernel);

        $currentSessionOrder = $sessionStatus['order'];
        $currentWaitNumber = $sessionStatus['order'] - $_limit;

        $this->assertSame(1, $sessionStatus['count']);
        $this->assertSame(1, $currentSessionOrder);

        $this->assertSame($currentWaitNumber, $sessionStatus['queue']);

        // The second visitor.
        $kernel->setIp('140.112.172.12');
        $methodSetSessionId = $reflection->getMethod('setSessionId');
        $methodSetSessionId->setAccessible(true);
        $methodSetSessionId->invokeArgs($kernel, [md5(date('YmdHis') . mt_rand(1, 1000))]);

        $result = $kernel->run();
        $t = $reflection->getProperty('sessionStatus');
        $t->setAccessible(true);
        $sessionStatus = $t->getValue($kernel);

        $currentSessionOrder = $sessionStatus['order'];

        $this->assertSame(2, $currentSessionOrder);
        $this->assertSame($kernel::RESPONSE_ALLOW, $result);

        // The third visitor.
        $kernel->setIp('140.112.172.13');
        $methodSetSessionId = $reflection->getMethod('setSessionId');
        $methodSetSessionId->setAccessible(true);
        $methodSetSessionId->invokeArgs($kernel, [md5(date('YmdHis') . mt_rand(1001, 2000))]);

        $result = $kernel->run();
        $t = $reflection->getProperty('sessionStatus');
        $t->setAccessible(true);
        $sessionStatus = $t->getValue($kernel);

        $currentSessionOrder = $sessionStatus['order'];
        $this->assertSame(3, $currentSessionOrder);
        $this->assertSame($kernel::RESPONSE_ALLOW, $result);

        // The fourth visitor.
        $kernel->setIp('140.112.172.14');
        $methodSetSessionId = $reflection->getMethod('setSessionId');
        $methodSetSessionId->setAccessible(true);
        $methodSetSessionId->invokeArgs($kernel, [md5(date('YmdHis') . mt_rand(2001, 3000))]);

        $result = $kernel->run();
        $t = $reflection->getProperty('sessionStatus');
        $t->setAccessible(true);
        $sessionStatus = $t->getValue($kernel);

        $currentSessionOrder = $sessionStatus['order'];
        $this->assertSame(4, $currentSessionOrder);
        $this->assertSame($kernel::RESPONSE_LIMIT_SESSION, $result);

        // The fifth vistor.
        $kernel->setIp('140.112.172.15');
        $methodSetSessionId = $reflection->getMethod('setSessionId');
        $methodSetSessionId->setAccessible(true);
        $methodSetSessionId->invokeArgs($kernel, [md5(date('YmdHis') . mt_rand(1, 999999))]);

        $result = $kernel->run();
        $this->assertSame($kernel::RESPONSE_LIMIT_SESSION, $result);

        // // Remove session if it expires.
        $kernel->limitSession($_limit, 1);
        sleep(3);
        $result = $kernel->run();

        $this->assertSame($kernel::RESPONSE_LIMIT_SESSION, $result);

        $result = $kernel->run();
        $this->assertSame($kernel::RESPONSE_ALLOW, $result);
    }

    public function testSetProperty()
    {
        $kernel = new \Shieldon\Firewall\Kernel();
        $kernel->setProperty();
        $kernel->setProperty('interval_check_session', 1);
        $kernel->setProperty('time_reset_limit', 1);
        $kernel->setProperty('limit_unusual_behavior', ['cookie' => 1, 'session' => 1, 'referer' => 1]);
        $kernel->setProperty('cookie_name', 'unittest');
        $kernel->setProperty('cookie_domain', 'localhost');
        $kernel->setProperty('display_online_info', true);
        $kernel->setProperty('display_lineup_info', true);
        $kernel->setProperty('display_user_info', true);

        $reflection = new \ReflectionObject($kernel);
        $t = $reflection->getProperty('properties');
        $t->setAccessible(true);
        $properties = $t->getValue($kernel);

        $this->assertSame($properties['interval_check_session'], 1);
        $this->assertSame($properties['time_reset_limit'], 1);
        $this->assertSame($properties['limit_unusual_behavior'], ['cookie' => 1, 'session' => 1, 'referer' => 1]);
        $this->assertSame($properties['cookie_name'], 'unittest');
        $this->assertSame($properties['cookie_domain'], 'localhost');
    }

    public function testSetDriver()
    {
        $kernel = new \Shieldon\Firewall\Kernel();
        $dbLocation = save_testing_file('shieldon_unittest.sqlite3');

        $pdoInstance = new \PDO('sqlite:' . $dbLocation);
        $driver = new \Shieldon\Firewall\Driver\SqliteDriver($pdoInstance);
        $kernel->add($driver);

        if ($kernel->driver === $driver) {
            $this->assertTrue(true);
        } else {
            $this->assertTrue(false);
        }
    }

    public function testSetLogger()
    {
        $kernel = new \Shieldon\Firewall\Kernel();
  
        $logger = new \Shieldon\Firewall\Log\ActionLogger(BOOTSTRAP_DIR . '/../tmp/shieldon');
        $kernel->add($logger);

        if ($kernel->logger === $logger) {
            $this->assertTrue(true);
        } else {
            $this->assertTrue(false);
        }
    }

    public function testCreateDatabase()
    {
        $kernel = new \Shieldon\Firewall\Kernel();
        $kernel->createDatabase(false);
    
        $reflection = new \ReflectionObject($kernel);
        $t = $reflection->getProperty('autoCreateDatabase');
        $t->setAccessible(true);
        $this->assertFalse($t->getValue($kernel));
    }

    public function testSetChannel($driver = 'sqlite')
    {
        $kernel = get_testing_shieldon_instance($driver);

        $kernel->setChannel('unittest');

        if ('unittest' === $kernel->driver->getChannel()) {
            $this->assertTrue(true);
        } else {
            $this->assertTrue(false);
        }

        // Test exception.
        $this->expectException(\LogicException::class);
        $kernel = new \Shieldon\Firewall\Kernel();
        $kernel->setChannel('unittest');
    }

    public function testSetCaptcha()
    {
        $kernel = new \Shieldon\Firewall\Kernel();
        $imageCaptcha = new \Shieldon\Firewall\Captcha\ImageCaptcha();
        $kernel->add($imageCaptcha);

        $reflection = new \ReflectionObject($kernel);
        $t = $reflection->getProperty('captcha');
        $t->setAccessible(true);
        $refectedCaptcha = $t->getValue($kernel);

        if ($refectedCaptcha['ImageCaptcha'] instanceof $imageCaptcha) {
            $this->assertTrue(true);
        } else {
            $this->assertTrue(false);
        }
    }

    public function testCaptchaResponse($driver = 'sqlite')
    {
        $kernel = new \Shieldon\Firewall\Kernel();
        $kernel->add(new \Shieldon\Firewall\Captcha\ImageCaptcha());
        $result = $kernel->captchaResponse();
        $this->assertFalse($result);

        $kernel = new \Shieldon\Firewall\Kernel();
        $_POST['shieldon_captcha'] = 'ok';
        reload_request();

        $result = $kernel->captchaResponse();
        $this->assertTrue($result);

        $kernel = get_testing_shieldon_instance($driver);

        $kernel->limitSession(1000, 9999);
        $reflection = new \ReflectionObject($kernel);
        $methodSetSessionId = $reflection->getMethod('setSessionId');
        $methodSetSessionId->setAccessible(true);
        $methodSetSessionId->invokeArgs($kernel, [md5(date('YmdHis') . mt_rand(2001, 3000))]);
        $result = $kernel->run();
        $_POST['shieldon_captcha'] = 'ok';
        reload_request();

        $result = $kernel->captchaResponse();
        $this->assertTrue($result);
    }

    public function testadd()
    {
        $kernel = new \Shieldon\Firewall\Kernel();
        $ipComponent = new \Shieldon\Firewall\Component\Ip();
        $kernel->add($ipComponent);

        if ($kernel->component['Ip'] === $ipComponent) {
            $this->assertTrue(true);
        } else {
            $this->assertTrue(false);
        }
    }

    public function testBan($driver = 'sqlite')
    {
        $kernel = get_testing_shieldon_instance($driver);
        $kernel->driver->rebuild();

        $kernel->ban();
        $result = $kernel->run();
        $this->assertSame($kernel::RESPONSE_DENY, $result);

        $kernel->unban();
        $this->assertSame($kernel::RESPONSE_ALLOW, $kernel->run());
    }

    public function testUnBan($driver = 'sqlite')
    {
        $kernel = get_testing_shieldon_instance($driver);
        $kernel->driver->rebuild();
        $kernel->setIp('33.33.33.33');

        $kernel->ban('33.33.33.33');
        $this->assertSame($kernel::RESPONSE_DENY, $kernel->run());

        $kernel->unban('33.33.33.33');
        $this->assertSame($kernel::RESPONSE_ALLOW, $kernel->run());
    }

    public function testRespond($driver = 'sqlite')
    {
        $_SERVER['REQUEST_URI'] = '/';
        reload_request();

        $kernel = get_testing_shieldon_instance($driver);
        $kernel->setProperty('display_lineup_info', true);
        $kernel->setProperty('display_user_info', true);
        $kernel->setProperty('display_online_info', true);
        $kernel->driver->rebuild();

        // Limit
        $kernel->setIp('33.33.33.33');
        $reflection = new \ReflectionObject($kernel);
        $methodSetSessionId = $reflection->getMethod('setSessionId');
        $methodSetSessionId->setAccessible(true);
        $methodSetSessionId->invokeArgs($kernel, [md5('hello, this is an unit test!')]);

        $kernel->limitSession(1, 30000);
        $result = $kernel->run();
        $this->assertSame($kernel::RESPONSE_ALLOW, $result);
        $result = $kernel->run();

        if ($result === $kernel::RESPONSE_LIMIT_SESSION) {
            $response = $kernel->respond();
            $output = $response->getBody()->getContents();

            if (strpos($output, 'Please line up') !== false) {
                $this->assertTrue(true);
            } else {
                $this->assertTrue(false);
            }
        }

        $kernel->limitSession(100, 30000);
        $kernel->setIp('33.33.33.33');
        $kernel->ban('33.33.33.33');
        $result = $kernel->run();

        if ($result === $kernel::RESPONSE_DENY) {
            $response = $kernel->respond();
            $output = $response->getBody()->getContents();

            if (strpos($output, 'Access denied') !== false) {
                $this->assertTrue(true);
            } else {
                $this->assertTrue(false);
            }
        } else {
            $this->assertTrue(false);
        }

        $kernel->setIp('141.112.175.1');

        $kernel->setProperty('display_lineup_info', false);
        $kernel->setProperty('display_user_info', false);
        $kernel->setProperty('display_online_info', false);

        $kernel->setProperty('time_unit_quota', [
            's' => 2,
            'm' => 20, 
            'h' => 60, 
            'd' => 240
        ]);

        $result = [];
        for ($i = 1; $i <= 5; $i++) {
            $result[$i] = $kernel->run();
        }

        $this->assertSame($kernel::RESPONSE_ALLOW, $result[1]);
        $this->assertSame($kernel::RESPONSE_ALLOW, $result[2]);
        if ($result[3] === $kernel::RESPONSE_TEMPORARILY_DENY) {
            $response = $kernel->respond();
            $output = $response->getBody()->getContents();

            if (stripos($output, 'Captcha') !== false) {
                $this->assertTrue(true);
            } else {
                $this->assertTrue(false);
            }
        } else {
            $this->assertTrue(false);
        }
    }

    public function testIpCompoment($driver = 'sqlite')
    {
        $kernel = new \Shieldon\Firewall\Kernel();
        
        $kernel = get_testing_shieldon_instance($driver);
        $kernel->driver->rebuild();

        $kernel->add(new \Shieldon\Firewall\Component\Ip());

        $kernel->setIp('8.8.8.8');

        // Set an IP to whitelist.
        $kernel->component['Ip']->setAllowedItem('8.8.8.8');
        $result = $kernel->run();
        $this->assertSame($kernel::RESPONSE_ALLOW, $result);

        // Set an IP to blacklist.

        $kernel->setIp('8.8.4.4');
        $kernel->component['Ip']->setDeniedItem('8.8.4.4');
        $result = $kernel->run();
        $this->assertSame($kernel::RESPONSE_DENY, $result);
    }

    public function testRun($driver = 'sqlite')
    {
        $kernel = new \Shieldon\Firewall\Kernel();

        $kernel = get_testing_shieldon_instance($driver);
        $kernel->driver->rebuild();

        $headerComponent = new \Shieldon\Firewall\Component\Header();
        $headerComponent->setStrict(true);

        $trustedBot = new \Shieldon\Firewall\Component\TrustedBot();
        $trustedBot->setStrict(true);

        $ip = new \Shieldon\Firewall\Component\Ip();
        $ip->setStrict(true);

        $userAgent = new \Shieldon\Firewall\Component\UserAgent();
        $userAgent->setStrict(true);

        $rdns = new \Shieldon\Firewall\Component\Rdns();
        $rdns->setStrict(true);

        $kernel->add($trustedBot);
        $kernel->add($ip);
        $kernel->add($headerComponent);
        $kernel->add($userAgent);
        $kernel->add($rdns);
        
        // By default, it will block this session because of no common header information

        $kernel->setIp('8.8.8.8');
        $result = $kernel->run();
        $this->assertSame($kernel::RESPONSE_DENY, $result);

        // Check trusted bots.

        // BING
        $kernel = get_testing_shieldon_instance($driver);
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)';
        $kernel->add(new \Shieldon\Firewall\Component\TrustedBot());
        $kernel->setIp('40.77.169.1', true);
   
        $result = $kernel->run();
        $this->assertSame($kernel::RESPONSE_ALLOW, $result);

        // Code coverage for - // is no more needed for that IP.
        $result = $kernel->run();
        $this->assertSame($kernel::RESPONSE_ALLOW, $result);

        // GOOGLE
        $kernel = get_testing_shieldon_instance($driver);
        $_SERVER['HTTP_USER_AGENT'] = 'Googlebot/2.1 (+http://www.google.com/bot.html)';
        $kernel->add(new \Shieldon\Firewall\Component\TrustedBot());
        $kernel->setIp('66.249.66.1', true);
        $result = $kernel->run();
        $this->assertSame($kernel::RESPONSE_ALLOW, $result);

        // Code coverage for - // is no more needed for that IP.
        $result = $kernel->run();
        $this->assertSame($kernel::RESPONSE_ALLOW, $result);

        // YAHOO
        $kernel = get_testing_shieldon_instance($driver);
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; Yahoo! Slurp; http://help.yahoo.com/help/us/ysearch/slurp)';
        $kernel->add(new \Shieldon\Firewall\Component\TrustedBot());
        $kernel->setIp('8.12.144.1', true);
        $result = $kernel->run();
        $this->assertSame($kernel::RESPONSE_ALLOW, $result);

        // Code coverage for - // is no more needed for that IP.
        $result = $kernel->run();
        $this->assertSame($kernel::RESPONSE_ALLOW, $result);

        // OTHER
        $kernel = get_testing_shieldon_instance($driver);
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)';
        $kernel->add(new \Shieldon\Firewall\Component\TrustedBot());
        $kernel->setIp('100.43.90.1', true);
        $result = $kernel->run();
        $this->assertSame($kernel::RESPONSE_ALLOW, $result);

        // Code coverage for - // is no more needed for that IP.
        $result = $kernel->run();
        $this->assertSame($kernel::RESPONSE_ALLOW, $result);

        $kernel = get_testing_shieldon_instance($driver);
        $kernel->disableFilters();
        $result = $kernel->run();
        $this->assertSame($kernel::RESPONSE_ALLOW, $result);
    }

    public function testGetSessionCount($driver = 'sqlite')
    {
        $kernel = get_testing_shieldon_instance($driver);
        $kernel->driver->rebuild();

        $reflection = new \ReflectionObject($kernel);
        $methodSetSessionId = $reflection->getMethod('setSessionId');
        $methodSetSessionId->setAccessible(true);

        $kernel->limitSession(100, 3600);

        for ($i = 1; $i <= 10; $i++) {
            $kernel->setIp(implode('.', [rand(1, 255), rand(1, 255), rand(1, 255), rand(1, 255)]));
            $methodSetSessionId->invokeArgs($kernel, [md5(date('YmdHis') . mt_rand(1, 999999))]);
            $kernel->run();
        }

        // Get how many people online.
        $sessionCount = $kernel->getSessionCount();

        $this->assertSame($sessionCount, 10);
    }

    public function testOutputJsSnippet()
    {
        $kernel = new \Shieldon\Firewall\Kernel();
        $js = $kernel->outputJsSnippet();

        if (! empty($js)) {
            $this->assertTrue(true);
        } else {
            $this->assertTrue(false);
        }
    }

    public function testDisableFiltering()
    {
        $kernel = new \Shieldon\Firewall\Kernel();
        $kernel->disableFilters();
        $reflection = new \ReflectionObject($kernel);
        $t = $reflection->getProperty('filterStatus');
        $t->setAccessible(true);
        $filterStatus = $t->getValue($kernel);

        $this->assertFalse($filterStatus['frequency']);
        $this->assertFalse($filterStatus['referer']);
        $this->assertFalse($filterStatus['cookie']);
        $this->assertFalse($filterStatus['session']);
    }

    public function testIPv6($driver = 'sqlite')
    {
        $kernel = get_testing_shieldon_instance($driver);
        $kernel->driver->rebuild();
        $kernel->setIp('0:0:0:0:0:ffff:c0a8:5f01');
        $result = $kernel->run();

        $ipDetail = $kernel->driver->get('0:0:0:0:0:ffff:c0a8:5f01', 'filter');

        $this->assertSame($ipDetail['ip'], '0:0:0:0:0:ffff:c0a8:5f01'); 
    }

    /***********************************************
     * File Driver 
     ***********************************************/

    public function testDetect_fileDriver()
    {
        $this->testDetectByFilterFrequency('file');
        $this->testDetectByFilterSession('file');
        $this->testDetectByFilterReferer('file');
        $this->testDetectByFilterCookie('file');
        $this->testResetFilterFlagChecks('file');
    }

    public function testAction_fileDriver()
    {
        $this->testAction('file');
    }

    public function testSessionHandler_fileDriver()
    {
        $this->testSessionHandler('file');
    }

    public function testSetChannel_fileDriver()
    {
        $this->testSetChannel('file');
    }

    public function testCaptchaResponse_fileDriver()
    {
        $this->testCaptchaResponse('file');
    }

    public function testBan_fileDriver()
    {
        $this->testBan('file');
    }

    public function testUnBan_fileDriver()
    {
        $this->testUnBan('file');
    }

    public function testRun_fileDriver()
    {
        $this->testRun('file');
    }

    public function testGetSessionCount_fileDriver()
    {
        $this->testGetSessionCount('file');
    }

    public function testIPv6_fileDriver()
    {
        $this->testIPv6('file');
    }

    /***********************************************
     * MySQL Driver 
     ***********************************************/

    public function testDetect_mysqlDriver()
    {
        $this->testDetectByFilterFrequency('mysql');
        $this->testDetectByFilterSession('mysql');
        $this->testDetectByFilterReferer('mysql');
        $this->testDetectByFilterCookie('mysql');
        $this->testResetFilterFlagChecks('mysql');
    }

    public function testAction_mysqlDriver()
    {
        $this->testAction('mysql');
    }

    public function testSessionHandler_mysqlDriver()
    {
        $this->testSessionHandler('mysql');
    }

    public function testSetChannel_mysqlDriver()
    {
        $this->testSetChannel('mysql');
    }

    public function testCaptchaResponse_mysqlDriver()
    {
        $this->testCaptchaResponse('mysql');
    }

    public function testBan_mysqlDriver()
    {
        $this->testBan('mysql');
    }

    public function testUnBan_mysqlDriver()
    {
        $this->testUnBan('mysql');
    }

    public function testRun_mysqlDriver()
    {
        $this->testRun('mysql');
    }

    public function testGetSessionCount_mysqlDriver()
    {
        $this->testGetSessionCount('mysql');
    }

    public function testIPv6_mysqlDriver()
    {
        $this->testIPv6('mysql');
    }

    /***********************************************
     * Redis Driver 
     ***********************************************/

    public function testDetect_redisDriver()
    {
        $this->testDetectByFilterFrequency('redis');
        $this->testDetectByFilterSession('redis');
        $this->testDetectByFilterReferer('redis');
        $this->testDetectByFilterCookie('redis');
        $this->testResetFilterFlagChecks('redis');
    }

    public function testAction_redisDriver()
    {
        $this->testAction('redis');
    }

    public function testSessionHandler_redisDriver()
    {
        $this->testSessionHandler('redis');
    }

    public function testSetChannel_redisDriver()
    {
        $this->testSetChannel('redis');
    }

    public function testCaptchaResponse_redisDriver()
    {
        $this->testCaptchaResponse('redis');
    }

    public function testBan_redisDriver()
    {
        $this->testBan('redis');
    }

    public function testUnBan_redisDriver()
    {
        $this->testUnBan('redis');
    }

    public function testRun_redisDriver()
    {
        $this->testRun('redis');
    }

    public function testGetSessionCount_redisDriver()
    {
        $this->testGetSessionCount('redis');
    }

    public function testIPv6_redisDriver()
    {
        $this->testIPv6('redis');
    }

    /*****************/

    public function testSetMessenger()
    {
        $kernel = new \Shieldon\Firewall\Kernel();

        $telegram = new \Shieldon\Messenger\Telegram('test', 'test');

        $kernel->add($telegram);
    }

    public function testSetDialogUI()
    {
        $kernel = new \Shieldon\Firewall\Kernel();

        $kernel->setDialogUI([]);
    }

    public function testSetExcludedUrls()
    {
        $kernel = new \Shieldon\Firewall\Kernel();

        $kernel->setExcludedUrls([]);
    }

    public function testSetClosure()
    {
        $kernel = new \Shieldon\Firewall\Kernel();

        $kernel->setClosure('key', function() {
            return true;
        });
    }

    public function testManagedBy()
    {
        $kernel = new \Shieldon\Firewall\Kernel();

        $kernel->managedBy('demo');
    }

    /***********************************************
     * Test for building bridge to Iptable 
     ***********************************************/

    public function testDenyAttempts()
    {
        $kernel = get_testing_shieldon_instance('file');

        //$_SERVER['HTTP_USER_AGENT'] = 'google';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/70.0.3538.77 Safari/537.36';

        $kernel->add(new \Shieldon\Firewall\Component\TrustedBot());
        $kernel->add(new \Shieldon\Firewall\Component\Ip());
        $kernel->add(new \Shieldon\Firewall\Component\UserAgent());
        
        $kernel->add(new \Shieldon\Firewall\Component\Rdns());

        $kernel->add(new \MockMessenger());

        $kernel->setChannel('test_shieldon_deny_attempt');
        $kernel->driver->rebuild();

        $kernel->setProperty('deny_attempt_enable', [
            'data_circle' => true,
            'system_firewall' => true, 
        ]);

        $kernel->setProperty('deny_attempt_notify', [
            'data_circle' => true,
            'system_firewall' => true, 
        ]);

        $kernel->setProperty('deny_attempt_buffer', [
            'data_circle' => 2,
            'system_firewall' => 2, 
        ]);

        $kernel->setProperty('reset_attempt_counter', 5);

        // Test for IPv4 and IPv6.
        foreach (['127.0.1.1', '2607:f0d0:1002:51::4'] as $ip) {

            $kernel->setIp($ip);

            $result = $kernel->run();
            $this->assertEquals($result, $kernel::RESPONSE_ALLOW);
    
            $result = $kernel->run();
            $this->assertEquals($result, $kernel::RESPONSE_ALLOW);
    
            $result = $kernel->run();
            $this->assertEquals($result, $kernel::RESPONSE_TEMPORARILY_DENY);
    
            $result = $kernel->run();
            $this->assertEquals($result, $kernel::RESPONSE_TEMPORARILY_DENY);
    
            $result = $kernel->run();
            $this->assertEquals($result, $kernel::RESPONSE_TEMPORARILY_DENY);
    
            $result = $kernel->run();
            $this->assertEquals($result, $kernel::RESPONSE_DENY);
    
            $result = $kernel->run();
            $this->assertEquals($result, $kernel::RESPONSE_DENY);
    
            $result = $kernel->run();
            $this->assertEquals($result, $kernel::RESPONSE_DENY);
        }

        // Test for IPv4 and IPv6. 
        foreach (['127.0.1.2', '2607:f0d0:1002:52::4'] as $ip) {

            $kernel->setIp($ip);

            $result = $kernel->run();
            $this->assertEquals($result, $kernel::RESPONSE_ALLOW);
    
            $result = $kernel->run();
            $this->assertEquals($result, $kernel::RESPONSE_ALLOW);
    
            $result = $kernel->run();
            $this->assertEquals($result, $kernel::RESPONSE_TEMPORARILY_DENY);

            sleep(7);
    
            $result = $kernel->run();
            $this->assertEquals($result, $kernel::RESPONSE_TEMPORARILY_DENY);
    
            $result = $kernel->run();
            $this->assertEquals($result, $kernel::RESPONSE_TEMPORARILY_DENY);

            $result = $kernel->run();
            $this->assertEquals($result, $kernel::RESPONSE_TEMPORARILY_DENY);
    
            $result = $kernel->run();
            $this->assertEquals($result, $kernel::RESPONSE_DENY);
    
            $result = $kernel->run();
            $this->assertEquals($result, $kernel::RESPONSE_DENY);
        }
    }

    public function testFakeTrustedBot()
    {
        $_SERVER['HTTP_USER_AGENT'] = 'google';

        $kernel = get_testing_shieldon_instance();
        $kernel->add(new \Shieldon\Firewall\Component\TrustedBot());
        $kernel->disableFilters();
        $result = $kernel->run();

        $this->assertSame($kernel::RESPONSE_DENY, $result);
    }

    public function testSetAndGetTemplate()
    {
        $kernel = new \Shieldon\Firewall\Kernel();
        $kernel->setTemplateDirectory(BOOTSTRAP_DIR . '/../templates/frontend');

        $reflection = new \ReflectionObject($kernel);
        $methodGetTemplate = $reflection->getMethod('getTemplate');
        $methodGetTemplate->setAccessible(true);
        $tpl = $methodGetTemplate->invokeArgs($kernel, ['captcha']);

        $this->assertSame($tpl, BOOTSTRAP_DIR . '/../templates/frontend/captcha.php');

        $this->expectException(\RuntimeException::class);
        $tpl = $methodGetTemplate->invokeArgs($kernel, ['captcha2']);
    }

    public function testThrowEexceptionSpecificTemplateFileNotExist()
    {
        $this->expectException(\RuntimeException::class);

        $kernel = new \Shieldon\Firewall\Kernel();
        $kernel->setTemplateDirectory(BOOTSTRAP_DIR . '/../templates/frontend');

        $reflection = new \ReflectionObject($kernel);
        $methodGetTemplate = $reflection->getMethod('getTemplate');
        $methodGetTemplate->setAccessible(true);
  
        $tpl = $methodGetTemplate->invokeArgs($kernel, ['captcha2']);
    }

    public function testThrowEexceptionWhenNoDriver()
    {
        $this->expectException(\RuntimeException::class);
        $kernel = new \Shieldon\Firewall\Kernel();
        $kernel->run();
    }

    public function testThrowEexceptionWhenTemplateDirectoryNotExist()
    {
        $this->expectException(\InvalidArgumentException::class);
        $kernel = new \Shieldon\Firewall\Kernel();
        $kernel->setTemplateDirectory('/not-exists');
        $kernel->run();
    }

    public function testThrowEexceptionWhenTemplateFileNotExist()
    {
        $this->expectException(\RuntimeException::class);
        $kernel = new \Shieldon\Firewall\Kernel();
        $kernel->setTemplateDirectory('/');
        $kernel->run();
    }

    public function testAddAndRemoveClasses()
    {
        $kernel = new \Shieldon\Firewall\Kernel();
        $kernel->add(new \Shieldon\Messenger\Telegram('test', 'test'));
        $kernel->add(new \Shieldon\Messenger\Sendgrid('test'));
        $kernel->add(new \Shieldon\Firewall\Driver\FileDriver(BOOTSTRAP_DIR . '/../tmp/shieldon'));

        $reflection = new \ReflectionObject($kernel);
        $t = $reflection->getProperty('messenger');
        $d = $reflection->getProperty('driver');
        $t->setAccessible(true);
        $d->setAccessible(true);
        $messengers = $t->getValue($kernel);
        $driver = $d->getValue($kernel);

        $this->assertTrue(($messengers[0] instanceof \Shieldon\Messenger\Telegram));
        $this->assertTrue(($messengers[1] instanceof \Shieldon\Messenger\Sendgrid));
        $this->assertTrue(($driver instanceof \Shieldon\Firewall\Driver\FileDriver));

        $kernel->remove('messenger');
        $kernel->remove('driver');

        $messengers = $t->getValue($kernel);
        $driver = $d->getValue($kernel);

        $this->assertEquals(count($messengers), 0);
        $this->assertEquals($driver, null);
    }

    public function testAddAndRemoveSpecificClass()
    {
        $kernel = new \Shieldon\Firewall\Kernel();
        $kernel->add(new \Shieldon\Messenger\Telegram('test', 'test'));
        $kernel->add(new \Shieldon\Messenger\Sendgrid('test'));
        $kernel->add(new \Shieldon\Firewall\Driver\FileDriver(BOOTSTRAP_DIR . '/../tmp/shieldon'));

        $reflection = new \ReflectionObject($kernel);
        $t = $reflection->getProperty('messenger');
        $d = $reflection->getProperty('driver');
        $t->setAccessible(true);
        $d->setAccessible(true);
        $messengers = $t->getValue($kernel);
        $driver = $d->getValue($kernel);

        $kernel->remove('messenger', 'Telegram');
        $kernel->remove('driver', 'FileDriver');

        $messengers = $t->getValue($kernel);
        $driver = $d->getValue($kernel);

        $this->assertEquals(count($messengers), 1);
        $this->assertEquals($driver, null);
    }
}