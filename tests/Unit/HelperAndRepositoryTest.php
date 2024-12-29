<?php

namespace Tests\Unit;

use Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use App\Helpers\TeHelper;
use App\Repository\UserRepository;
use App\Models\User;

class HelperAndRepositoryTest extends TestCase
{
    /**
     * Test the willExpireAt method in TeHelper
     */
    public function testWillExpireAt()
    {
        $dueTime = Carbon::now()->addDays(2)->toDateTimeString();
        $createdAt = Carbon::now()->toDateTimeString();

        $result = TeHelper::willExpireAt($dueTime, $createdAt);

        $this->assertNotNull($result);
        $this->assertIsString($result);
        $this->assertEquals(Carbon::parse($dueTime)->format('Y-m-d H:i:s'), $result);

        // Add more assertions for different cases if necessary
    }

    /**
     * Test the createOrUpdate method in UserRepository
     */
    public function testCreateOrUpdate()
    {
        $repository = new UserRepository(new User());

        $request = [
            'role' => 'admin',
            'name' => 'Test User',
            'email' => 'testuser@example.com',
            'dob_or_orgid' => '1990-01-01',
            'phone' => '123456789',
            'mobile' => '987654321',
            'password' => 'password123',
            'company_id' => '',
            'department_id' => '',
            'consumer_type' => 'paid',
            'customer_type' => 'individual',
            'username' => 'testuser',
            'post_code' => '12345',
            'address' => '123 Test Street',
            'city' => 'Test City',
            'town' => 'Test Town',
            'country' => 'Test Country',
            'status' => '1',
        ];

        // Create a user
        $user = $repository->createOrUpdate(null, $request);

        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals('Test User', $user->name);
        $this->assertEquals('admin', $user->user_type);
        $this->assertTrue(Hash::check('password123', $user->password));

        // Update the user
        $request['name'] = 'Updated User';
        $updatedUser = $repository->createOrUpdate($user->id, $request);

        $this->assertEquals('Updated User', $updatedUser->name);
    }
}