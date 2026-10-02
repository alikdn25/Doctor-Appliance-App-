<?php

use App\Support\PhoneNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The product is international (SPEC §1.1): country and regional format per company, currency stored with every
 * amount, compound and tax-inclusive taxes, country-neutral address fields, E.164 phones and generic payment methods.
 * Existing companies were all created in Canada, so they get country CA and regional format en-CA.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->char('country', 2)->nullable()->after('subscription_status');
            // Regional format (BCP 47) for dates, times and numbers, e.g. en-US, en-CA, en-GB.
            $table->string('locale', 20)->nullable()->after('timezone_pending');
            // Prices on estimates and invoices are entered with tax included (UK/EU/AU style).
            $table->boolean('prices_include_tax')->default(false)->after('currency');
        });

        DB::table('companies')->update(['country' => 'CA', 'locale' => 'en-CA']);

        Schema::table('companies', function (Blueprint $table) {
            $table->char('country', 2)->nullable(false)->change();
            $table->string('locale', 20)->nullable(false)->change();
            $table->string('timezone', 64)->default(null)->change();
            $table->char('currency', 3)->default(null)->change();
        });

        Schema::table('tax_rates', function (Blueprint $table) {
            // Charged on the amount plus the taxes listed before it.
            $table->boolean('is_compound')->default(false)->after('rate');
        });

        foreach (['estimates', 'invoices'] as $documents) {
            Schema::table($documents, function (Blueprint $table) {
                $table->char('currency', 3)->nullable()->after('status');
                $table->boolean('prices_include_tax')->default(false)->after('discount_value');
            });

            DB::statement("UPDATE {$documents} d SET currency = c.currency FROM companies c WHERE c.id = d.company_id");

            Schema::table($documents, fn (Blueprint $table) => $table->char('currency', 3)->nullable(false)->change());
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->char('currency', 3)->nullable()->after('amount');
        });
        DB::statement('UPDATE payments p SET currency = i.currency FROM invoices i WHERE i.id = p.invoice_id');
        Schema::table('payments', fn (Blueprint $table) => $table->char('currency', 3)->nullable(false)->change());

        DB::table('payments')->where('method', 'cheque')->update(['method' => 'check']);
        DB::table('payments')->where('method', 'e_transfer')->update(['method' => 'bank_transfer']);

        // HomeStars (Canada only) becomes one "online directory" lead source for every country.
        foreach (['customers', 'service_jobs'] as $table) {
            DB::table($table)->where('lead_source', 'homestars')->update(['lead_source' => 'directory']);
        }

        foreach (['properties', 'brand_addresses'] as $addresses) {
            Schema::table($addresses, function (Blueprint $table) {
                $table->renameColumn('province', 'region');
            });
            Schema::table($addresses, function (Blueprint $table) {
                $table->char('country', 2)->default(null)->change();
            });
        }

        Schema::table('brands', function (Blueprint $table) {
            $table->renameColumn('gst_number', 'tax_number');
        });

        $this->phonesToE164();
    }

    public function down(): void
    {
        Schema::table('brands', fn (Blueprint $table) => $table->renameColumn('tax_number', 'gst_number'));

        foreach (['properties', 'brand_addresses'] as $addresses) {
            Schema::table($addresses, function (Blueprint $table) {
                $table->renameColumn('region', 'province');
                $table->char('country', 2)->default('CA')->change();
            });
        }

        DB::table('payments')->where('method', 'check')->update(['method' => 'cheque']);
        DB::table('payments')->where('method', 'bank_transfer')->update(['method' => 'e_transfer']);

        foreach (['customers', 'service_jobs'] as $table) {
            DB::table($table)->where('lead_source', 'directory')->update(['lead_source' => 'homestars']);
        }

        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('currency'));

        foreach (['estimates', 'invoices'] as $documents) {
            Schema::table($documents, fn (Blueprint $table) => $table->dropColumn(['currency', 'prices_include_tax']));
        }

        Schema::table('tax_rates', fn (Blueprint $table) => $table->dropColumn('is_compound'));

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['country', 'locale', 'prices_include_tax']);
            $table->string('timezone', 64)->default('America/Vancouver')->change();
            $table->char('currency', 3)->default('CAD')->change();
        });
    }

    /**
     * Customer, brand and on-site contact phones were stored as typed; they become E.164
     * (read as numbers of the company's country).
     */
    private function phonesToE164(): void
    {
        $countries = DB::table('companies')->pluck('country', 'id');

        DB::table('customer_phones')->orderBy('id')->each(function ($phone) use ($countries) {
            $e164 = PhoneNumber::normalize($phone->number, $countries[$phone->company_id] ?? 'US');
            DB::table('customer_phones')->where('id', $phone->id)->update(['number' => $e164, 'number_normalized' => $e164]);
        });

        foreach (['brands' => 'phone', 'properties' => 'site_contact_phone'] as $table => $column) {
            DB::table($table)->whereNotNull($column)->where($column, '!=', '')->orderBy('id')
                ->each(function ($row) use ($table, $column, $countries) {
                    DB::table($table)->where('id', $row->id)->update([
                        $column => PhoneNumber::normalize($row->{$column}, $countries[$row->company_id] ?? 'US'),
                    ]);
                });
        }
    }
};
