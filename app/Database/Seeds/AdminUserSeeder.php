<?php

namespace App\Database\Seeds;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Seeder;
use CodeIgniter\Shield\Entities\User;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = $this->adminCredentials();

        if ($admin['email'] === '' || $admin['password'] === '') {
            CLI::write('AdminUserSeeder skipped: set FLEETOS_ADMIN_EMAIL and FLEETOS_ADMIN_PASSWORD (or app/Config/Admin.php).', 'yellow');

            return;
        }

        $users = auth()->getProvider();
        $existing = $users->findByCredentials(['email' => $admin['email']]);

        if ($existing instanceof User) {
            $existing->fill([
                'username' => $admin['username'] !== '' ? $admin['username'] : $existing->username,
                'password' => $admin['password'],
                'active' => true,
            ]);

            $users->save($existing);
            $user = $users->findById($existing->id);
            CLI::write('Admin user updated: ' . $admin['email'], 'green');
        } else {
            $user = new User([
                'username' => $admin['username'] !== '' ? $admin['username'] : null,
                'email' => $admin['email'],
                'password' => $admin['password'],
                'active' => true,
            ]);

            $users->allowEmptyInserts()->save($user);
            $user = $users->findById($users->getInsertID());
            CLI::write('Admin user created: ' . $admin['email'], 'green');
        }

        if ($user instanceof User) {
            $group = $admin['group'] !== '' ? $admin['group'] : 'superadmin';
            $user->addGroup($group);
            CLI::write('Admin user group ensured: ' . $group, 'green');
        }
    }

    /**
     * @return array{email: string, password: string, username: string, group: string}
     */
    private function adminCredentials(): array
    {
        $email = trim((string) env('FLEETOS_ADMIN_EMAIL', ''));
        $password = (string) env('FLEETOS_ADMIN_PASSWORD', '');
        $username = trim((string) env('FLEETOS_ADMIN_USERNAME', ''));
        $group = trim((string) env('FLEETOS_ADMIN_GROUP', 'superadmin'));

        if (($email === '' || $password === '') && class_exists(\Config\Admin::class)) {
            $config = config('Admin');

            if ($config !== null) {
                $email = $email !== '' ? $email : trim((string) ($config->email ?? ''));
                $password = $password !== '' ? $password : (string) ($config->password ?? '');
                $username = $username !== '' ? $username : trim((string) ($config->username ?? ''));
                $group = $group !== '' ? $group : trim((string) ($config->group ?? 'superadmin'));
            }
        }

        return [
            'email' => $email,
            'password' => $password,
            'username' => $username,
            'group' => $group,
        ];
    }
}
