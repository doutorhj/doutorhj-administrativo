<?php

use Illuminate\Database\Seeder;

class ItempedidoTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DB::table('itempedidos')->delete();
        
        DB::table('itempedidos')->insert(array (
            0 =>
            array (
                'id'    => '1',
                'valor' => 1500.40,
                'agendamento_id' => 1,
            ),
       ));
    }
}
