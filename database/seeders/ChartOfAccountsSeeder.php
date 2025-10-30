<?php

namespace Database\Seeders;
use App\Models\ChartOfAccount;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ChartOfAccountsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $accounts = [
            ['account_name' => 'CASH BALANCES', 'account_code' => '1010101', 'nature' => 'Asset'],
            ['account_name' => 'BANK BALANCES', 'account_code' => '1010102', 'nature' => 'Asset'],
            ['account_name' => 'ACCOUNTS RECEIVABLE', 'account_code' => '1010401', 'nature' => 'Asset'],
            ['account_name' => 'OTHER RECEIVABLES', 'account_code' => '1010405', 'nature' => 'Asset'],
            ['account_name' => 'DOUBTFUL RECEIVABLE', 'account_code' => '1010406', 'nature' => 'Asset'],
            ['account_name' => 'DEPOSITS', 'account_code' => '1010502', 'nature' => 'Asset'],
            ['account_name' => 'LOCAL INVESTMENTS', 'account_code' => '1010601', 'nature' => 'Asset'],
            ['account_name' => 'DTS LICENSE GUARANTEE', 'account_code' => '1020104', 'nature' => 'Asset'],
            ['account_name' => 'FURNITURE & FIXTURE', 'account_code' => '1020105', 'nature' => 'Asset'],
            ['account_name' => 'MALINDO AIRWAYS', 'account_code' => '1020106', 'nature' => 'Asset'],
            ['account_name' => 'PHOTOCOPY MACHINE', 'account_code' => '1020107', 'nature' => 'Asset'],
            ['account_name' => 'ASIAN OVERLAND', 'account_code' => '1020108', 'nature' => 'Asset'],
            ['account_name' => 'CAR', 'account_code' => '1020109', 'nature' => 'Asset'],
            ['account_name' => 'ACCOUNTS PAYABLE', 'account_code' => '2010101', 'nature' => 'Liability'],
            ['account_name' => 'PROVISION OF INCOME', 'account_code' => '2010201', 'nature' => 'Liability'],
            ['account_name' => 'OTHER PAYABLE', 'account_code' => '2010301', 'nature' => 'Liability'],
            ['account_name' => 'GR ALI CAPITAL', 'account_code' => '3010201', 'nature' => 'Equity'],
            ['account_name' => 'RETAINED EARNING', 'account_code' => '302', 'nature' => 'Equity'],
            ['account_name' => 'SERVICES SALE', 'account_code' => '4010101', 'nature' => 'Revenue'],
            ['account_name' => 'SERVICES PURCHASE', 'account_code' => '5010101', 'nature' => 'Expense'],
            ['account_name' => 'ADMINISTRATIVE EXPENSES', 'account_code' => '5020101', 'nature' => 'Expense'],
        ];

        foreach ($accounts as $account) {
            ChartOfAccount::create([
                'account_code' => $account['account_code'],
                'account_name' => $account['account_name'],
                'nature' => $account['nature'],
                'parent_id' => null, // Level 0 (Parent Accounts)
                'level' => 0,
                'status' => 1,
            ]);
        }
    }
}
