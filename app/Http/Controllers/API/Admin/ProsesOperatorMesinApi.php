<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProsesPesanan;
use App\Models\DetailPesanan;
use App\Models\Operator;
use App\Models\Mesin;
use App\Models\Pesanan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class ProsesOperatorMesinApi extends Controller
{
    /**
     * Mendapatkan daftar proses produksi aktif
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getActiveProcesses(Request $request)
    {
        try {
            $query = ProsesPesanan::with([
                'detailPesanan.custom.item',
                'detailPesanan.custom.bahan',
                'detailPesanan.custom.ukuran',
                'detailPesanan.pesanan',
                'operator',
                'mesin'
            ])
            ->whereNull('waktu_selesai')
            ->where('status_proses', '!=', 'Selesai');
            
            // Filter berdasarkan operator
            if ($request->has('operator_id') && !empty($request->operator_id)) {
                $query->where('operator_id', $request->operator_id);
            }
            
            // Filter berdasarkan mesin
            if ($request->has('mesin_id') && !empty($request->mesin_id)) {
                $query->where('mesin_id', $request->mesin_id);
            }
            
            // Filter berdasarkan status proses
            if ($request->has('status_proses') && !empty($request->status_proses)) {
                $query->where('status_proses', $request->status_proses);
            }
            
            // Pengurutan
            $sortBy = $request->get('sort_by', 'waktu_mulai');
            $sortDirection = $request->get('sort_direction', 'desc');
            $query->orderBy($sortBy, $sortDirection);
            
            // Eksekusi query
            $processes = $query->get();
            
            return response()->json([
                'success' => true,
                'proses_produksi' => $processes
            ]);
        } catch (\Exception $e) {
            Log::error('API Error: Gagal mendapatkan daftar proses produksi aktif - ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil daftar proses produksi aktif',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mendapatkan detail proses produksi berdasarkan ID
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        try {
            $process = ProsesPesanan::with([
                'detailPesanan.custom.item',
                'detailPesanan.custom.bahan',
                'detailPesanan.custom.ukuran',
                'detailPesanan.pesanan',
                'operator',
                'mesin'
            ])->findOrFail($id);
            
            return response()->json([
                'success' => true,
                'proses_pesanan' => $process
            ]);
        } catch (\Exception $e) {
            Log::error('API Error: Gagal mendapatkan detail proses produksi - ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil detail proses produksi',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mengubah status proses produksi
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateStatus(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'status_proses' => 'required|in:Mulai,Sedang Dikerjakan,Pause,Selesai',
                'catatan' => 'nullable|string'
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            DB::beginTransaction();
            
            $process = ProsesPesanan::with(['mesin', 'operator', 'detailPesanan.pesanan'])->findOrFail($id);
            $oldStatus = $process->status_proses;
            $newStatus = $request->status_proses;
            
            // Update data proses
            $process->status_proses = $newStatus;
            if ($request->has('catatan') && !empty($request->catatan)) {
                $process->catatan = $request->catatan;
            }
            
            // Jika status menjadi Selesai, update waktu selesai
            if ($newStatus == 'Selesai' && $oldStatus != 'Selesai') {
                $process->waktu_selesai = now();
                
                // Update status mesin menjadi aktif
                $mesin = $process->mesin;
                if ($mesin) {
                    Mesin::where('id', $mesin->id)->update(['status' => 'aktif']);
                }
                
                // Update status operator menjadi tidak_aktif
                $operator = $process->operator;
                if ($operator) {
                    // Cek apakah operator memiliki tugas lain yang masih aktif
                    $otherActiveProcesses = ProsesPesanan::where('operator_id', $operator->id)
                        ->where('id', '!=', $process->id)
                        ->whereNull('waktu_selesai')
                        ->where('status_proses', '!=', 'Selesai')
                        ->count();
                    
                    if ($otherActiveProcesses == 0) {
                        $operator->status = 'tidak_aktif';
                        $operator->save();
                    }
                }
                
                // Cek apakah semua proses untuk pesanan ini telah selesai
                $detailPesanan = $process->detailPesanan;
                if ($detailPesanan) {
                    $pesanan = $detailPesanan->pesanan;
                    if ($pesanan) {
                        // Cek apakah semua detail pesanan sudah selesai diproduksi
                        $allCompleted = true;
                        $pesananDetails = DetailPesanan::where('pesanan_id', $pesanan->id)->get();
                        
                        foreach ($pesananDetails as $detail) {
                            // Periksa apakah detail pesanan memiliki proses produksi
                            $detailProsesPesanan = ProsesPesanan::where('detail_pesanan_id', $detail->id)->first();
                            
                            // Jika tidak ada proses pesanan atau proses belum selesai, tandai belum selesai semua
                            if (!$detailProsesPesanan || $detailProsesPesanan->status_proses != 'Selesai') {
                                $allCompleted = false;
                                break;
                            }
                        }
                        
                        // Hanya ubah status jika semua produk selesai diproduksi
                        if ($allCompleted) {
                            // Cek metode pengambilan untuk menentukan status berikutnya
                            if ($pesanan->metode_pengambilan == 'ambil') {
                                $pesanan->status = 'Menunggu Pengambilan';
                            } else {
                                $pesanan->status = 'Sedang Dikirim';
                            }
                            $pesanan->save();
                            
                            Log::info('Semua produk dalam pesanan telah selesai diproduksi, status diubah', [
                                'pesanan_id' => $pesanan->id,
                                'new_status' => $pesanan->status,
                                'total_produk' => $pesananDetails->count()
                            ]);
                        } else {
                            Log::info('Beberapa produk dalam pesanan masih dalam proses produksi', [
                                'pesanan_id' => $pesanan->id,
                                'current_status' => $pesanan->status,
                                'completed_current_detail' => $detailPesanan->id
                            ]);
                        }
                    }
                }
            }
            
            $process->save();
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Status proses produksi berhasil diperbarui',
                'proses_pesanan' => $process
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('API Error: Gagal mengubah status proses produksi - ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengubah status proses produksi',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mendapatkan daftar proses produksi berdasarkan status
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $status
     * @return \Illuminate\Http\JsonResponse
     */
    public function getProcessesByStatus(Request $request, $status)
    {
        try {
            if (!in_array($status, ['Mulai', 'Sedang Dikerjakan', 'Pause', 'Selesai'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Status tidak valid'
                ], 400);
            }
            
            $query = ProsesPesanan::with([
                'detailPesanan.custom.item',
                'detailPesanan.pesanan',
                'operator',
                'mesin'
            ])
            ->where('status_proses', $status);
            
            // Filter tambahan jika diperlukan
            if ($status == 'Selesai' && $request->has('date_range')) {
                // Contoh filter untuk proses selesai dalam rentang waktu tertentu
                if ($request->has('start_date') && !empty($request->start_date)) {
                    $query->whereDate('waktu_selesai', '>=', $request->start_date);
                }
                
                if ($request->has('end_date') && !empty($request->end_date)) {
                    $query->whereDate('waktu_selesai', '<=', $request->end_date);
                }
            }
            
            // Pengurutan
            $sortBy = $request->get('sort_by', ($status == 'Selesai' ? 'waktu_selesai' : 'waktu_mulai'));
            $sortDirection = $request->get('sort_direction', 'desc');
            $query->orderBy($sortBy, $sortDirection);
            
            // Paginasi
            $limit = $request->get('limit', 10);
            $processes = $query->paginate($limit);
            
            return response()->json([
                'success' => true,
                'status' => $status,
                'proses_produksi' => $processes
            ]);
        } catch (\Exception $e) {
            Log::error('API Error: Gagal mendapatkan daftar proses produksi berdasarkan status - ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil daftar proses produksi',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}