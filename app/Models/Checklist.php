<?php

namespace App\Models;

use App\Services\Feriados;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Checklist extends Model
{
    protected $fillable = [
        'date', 'status',
    ];

    public function checklistProduct()
    {
        return $this->hasMany(ChecklistProduct::class)->with(['product']);
    }

    public function checklistTotals()
    {
        return $this->hasMany(ChecklistTotal::class);
    }

    /**
     * Fechar Checklist
     *
     * @param Checklist $checklist
     * @return array|mixed
     * @throws \Throwable
     */
    public function closeChecklist(Checklist $checklist)
    {
        /*
         * Se checklist estiver status 1 = aberto
         */
        if ($checklist->status) {

            return DB::transaction(function () use ($checklist) {

                /*
                 * Retornar as contagens dos produtos
                 */
                $checklist = $checklist::with('checklistProduct')->find($checklist->id);

                /*
                 * Formatando a data para o formato string
                 */
                $date = (new \DateTime($checklist->date))->format('Y-m-d');

                /*
                 * Verificando se há checklist de datas anteriores com status 1 aberto
                 */
                $checklistAbertos = self::where('date', '<', $date)->where('status', 1)->get();

                if ($checklistAbertos->count()) {

                    $dates = [];
                    foreach ($checklistAbertos as $checklistAberto) {
                        $dates[] = (new \DateTime($checklistAberto->date))->format('d/m/Y');
                    }

                    return [
                        'success' => false,
                        'message' => 'Os Checklists não foram fechados (' . implode(', ', $dates) . ')',
                    ];
                }

                /*
                 * Verificando se todos os produtos foram contados
                 */
                $checklistProductCount = $checklist->checklistProduct()->count();
                $productsCount         = Product::where(['active' => 1])->count();

                if ((!count($checklistProductCount)) || ($checklistProductCount < $productsCount)) {
                    return [
                        'success' => false,
                        'message' => ($checklistProductCount < $productsCount) . ' produto(s) não foi(ram) verificado(s).',
                    ];
                }

                /**
                 * Retornar todos os feriados anuais
                 */
                $feriados     = new Feriados();
                $diasFeriados = $feriados->getArrayFeriados();

                /*
                 * Retornando o último checklist fechado
                 */
                $checklistAnterior = self::where('date', '<', $date)->where('status', 0)
                    ->orderBy('date', 'desc')->with(['checklistProduct'])->first();

                /**
                 * Manipulando todos os produtos ativos
                 */
                foreach ($checklist->checklistProduct as $checklistProduct) {

                    //if ($checklistProduct->product_id != 20) continue;
                    //$feriados = new Feriados();
                    //dd($feriados->getArrayFeriados());

                    /*
                     * Verificando e retornando se houve produção do produto na data do checklist
                     */
                    $production = Production::where([
                        'date' => $date, 'product_id' => $checklistProduct->product_id,
                    ])->first();

                    /**
                     * Variável que armazena o total atual do produto
                     * o Produto é novo seu valor default é 0
                     * se houver chacklist do dia anterior o valor é a quantidade que ficou
                     */
                    $totalAnterior = 0;

                    /**
                     * A variável $difference a quantidade de saida deste produto.
                     */
                    $difference = 0;

                    if ($checklistAnterior) {

                        /**
                         * Retornando a contagem do produto no chacklist anterior
                         */
                        $checklistTotalAnterior = ChecklistProduct::with(['checklist_tatals'])->where([
                            'checklist_id' => $checklistAnterior->id,
                            'product_id'   => $checklistProduct->product_id,
                        ])->first();

                        /**
                         * Se houver checklist no dia anterior alterar a variável $totalAnterior
                         * com o total do checklist do dia anterior
                         */
                        if ($checklistTotalAnterior)
                            $totalAnterior = $checklistTotalAnterior->checklist_tatals->total;

                        /**
                         * Se houver produção desse produto neste dia,
                         * alterar a variável $totalAnterior somando a quantidade produzida
                         */
                        if ($production) {
                            $totalAnterior = $totalAnterior + $production->quantity;
                        }

                        /**
                         * Se há checklist do dia anterior e/ou se houve produção do produto
                         * a variável $totalAnterior será maior que zero
                         * então esse valor menos o total contado neste dia será a quantidade
                         * que foi utilizada.
                         */
                        if ($totalAnterior > 0)
                            $difference = $totalAnterior - $checklistProduct->total;

                    }

                    /**
                     * Retorna as quantidades da tabela de referência de saída dos produtos
                     */
                    $productDailyChecklist = ProductDailyChecklist::where(['product_id' => $checklistProduct->product_id])->first();
                    /**
                     * Convertendo json em array
                     */
                    $productDailyChecklistDays = json_decode($productDailyChecklist->days);

                    /**
                     * Se a quantidade de saída for maior que a quantidade que está na tabel de referência,
                     * alterar o valor da tabel de referência.
                     */
                    $numberOfWeek = date('w', strtotime($checklist->date));

                    /**
                     * Verificar se o dia atual é feriado então
                     */
                    if (in_array((new \DateTime($checklist->date))->format('m-d'), $diasFeriados)) {
                        $numberOfWeek = 6; // sábados e feriados
                    }

                    $daysOfTheWeek = getKeyDaysOfTheWeek($numberOfWeek);
                    if ($difference > $productDailyChecklistDays[$daysOfTheWeek]) {
                        $productDailyChecklistDays[$daysOfTheWeek] = $difference;

                        $productDailyChecklist->fill([
                            'days' => json_encode($productDailyChecklistDays),
                        ]);

                        $productDailyChecklist->save();
                    }

                    /*
                     * Verificar se o total contado do produto é menor que o valor da tabela diária
                     * tendo então que criar uma tarefa.
                     */
                    $numberOfWeek = date('w', strtotime($checklist->date . "+1 days"));

                    /**
                     * Verificar se o próximo dia é feriado então
                     */
                    if (in_array((new \DateTime(date('Y-m-d', strtotime($checklist->date . "+1 days"))))->format('m-d'), $diasFeriados)) {
                        $numberOfWeek = 6; // sábados e feriados
                    }

                    $daysOfTheWeek = getKeyDaysOfTheWeek($numberOfWeek);
                    if ($checklistProduct->total < $productDailyChecklistDays[$daysOfTheWeek]) {

                        $missingAmount = $productDailyChecklistDays[$daysOfTheWeek] - $checklistProduct->total;

                        $task = Task::where(['product_id' => $checklistProduct->product_id, 'status' => 1])->first();

                        if (!$task)
                            Task::create([
                                'product_id'  => $checklistProduct->product_id,
                                'description' => "Faltará: $missingAmount",
                            ]);
                    }

                    $data = [
                        'checklist_id'         => $checklist->id,
                        'checklist_product_id' => $checklistProduct->id,
                        'total'                => $checklistProduct->total,
                        'difference'           => $difference,
                    ];

                    if ($difference < 0) {

                        return [
                            'success' => false,
                            'message' => "Opss, erro ao finalizar o produto {$checklistProduct->product->name}.",
                        ];
                    }

                    $checklistTotal = ChecklistTotal::where([
                        'checklist_id'         => $data['checklist_id'],
                        'checklist_product_id' => $data['checklist_product_id'],
                    ])->first();
                    if ($checklistTotal) {
                        $checklistTotal->fill($data);
                        $checklistTotal->save();
                    } else {
                        ChecklistTotal::updateOrCreate($data);
                    }
                }

                $checklist->status = 0;
                $checklist->save();

                return [
                    'success' => true,
                    'message' => 'Checklist finalizado com sucesso.',
                ];

            });
        }

        return [
            'success' => false,
            'message' => 'This checklist already been finalized.',
        ];
    }

    public function getDateAttribute($value)
    {
        $c = Carbon::createFromFormat('Y-m-d', $value);

        return $c->toW3cString();
    }

    public function getCreatedAtAttribute($value)
    {
        $c = Carbon::createFromFormat('Y-m-d H:i:s', $value);

        return $c->toW3cString();
    }

    public function getUpdatedAtAttribute($value)
    {
        $c = Carbon::createFromFormat('Y-m-d H:i:s', $value);

        return $c->toW3cString();
    }
}
