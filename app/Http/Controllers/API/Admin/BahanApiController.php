<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\Bahan;
use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class BahanApiController extends Controller
{
    /**
     * Menampilkan semua bahans
     */
    public function index()
    {
        try {
            Log::info('API: Request untuk daftar bahan diterima');
            // Eager load relations untuk mengurangi N+1 query problem
            $bahans = Bahan::with('items')->get();
            
            return response()->json([
                'success' => true,
                'bahans' => $bahans
            ]);
        } catch (\Exception $e) {
            Log::error('API: Error pada index bahan: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil data bahan',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Menyimpan bahan baru
     */
    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'nama_bahan' => 'required|string|max:255',
                'biaya_tambahan' => 'required|numeric|min:0',
                'item_ids' => 'nullable|array',
                'item_ids.*' => 'exists:items,id'
            ]);
            
            DB::beginTransaction();
            
            $bahan = new Bahan();
            $bahan->nama_bahan = $request->nama_bahan;
            $bahan->biaya_tambahan = $request->biaya_tambahan;
            $bahan->save();
            
            // Jika ada item yang dipilih, hubungkan dengan bahan ini
            if ($request->has('item_ids') && !empty($request->item_ids)) {
                $bahan->items()->attach($request->item_ids);
            }
            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Bahan berhasil ditambahkan',
                'bahan' => $bahan,
                'items' => $bahan->items
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menyimpan bahan',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Menampilkan bahan berdasarkan id
     */
    public function show($id)
    {
        try {
            $bahan = Bahan::with('items')->find($id);
            
            if (!$bahan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bahan tidak ditemukan'
                ], 404);
            }
            
            return response()->json([
                'success' => true,
                'bahan' => $bahan,
                'items' => $bahan->items
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil data bahan',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Memperbarui bahan berdasarkan id
     */
    public function update(Request $request, $id)
    {
        try {
            $validatedData = $request->validate([
                'nama_bahan' => 'required|string|max:255',
                'biaya_tambahan' => 'required|numeric|min:0',
                'item_ids' => 'nullable|array',
                'item_ids.*' => 'exists:items,id'
            ]);
            
            $bahan = Bahan::find($id);
            
            if (!$bahan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bahan tidak ditemukan'
                ], 404);
            }
            
            DB::beginTransaction();
            
            $bahan->nama_bahan = $request->nama_bahan;
            $bahan->biaya_tambahan = $request->biaya_tambahan;
            $bahan->save();
            
            // Update relasi dengan item
            if ($request->has('item_ids')) {
                $bahan->items()->sync($request->item_ids);
            }
            
            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Bahan berhasil diperbarui',
                'bahan' => $bahan,
                'items' => $bahan->items()->get()
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui bahan',
                'error' => $e->getMessage()
            ], 500);
        }
    }

   /**
     * Menghapus bahan berdasarkan id
     */
    public function destroy($id)
    {
        try {
            // Gunakan transaction untuk memastikan semua operasi berhasil atau tidak sama sekali
            return DB::transaction(function() use ($id) {
                $bahan = Bahan::find($id);
                
                if (!$bahan) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Bahan tidak ditemukan'
                    ], 404);
                }
                
                // Cek apakah bahan digunakan dalam Custom (produk yang mungkin sudah dipesan)
                $customCount = DB::table('customs')->where('bahan_id', $id)->count();
                if ($customCount > 0) {
                    Log::warning('API: Bahan tidak dapat dihapus karena digunakan dalam tabel customs', [
                        'bahan_id' => $id,
                        'custom_count' => $customCount
                    ]);
                    
                    return response()->json([
                        'success' => false,
                        'message' => 'Bahan tidak dapat dihapus karena sudah digunakan dalam pesanan'
                    ], 400);
                }
                
                // Hapus relasi dengan item terlebih dahulu
                $bahan->items()->detach();
                
                // Hapus bahan
                $bahan->delete();
                return response()->json([
                    'success' => true,
                    'message' => 'Bahan berhasil dihapus'
                ]);
            });
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menghapus bahan: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Mendapatkan semua item berdasarkan bahan
     */
    public function getItemsByBahan($id)
    {
        try {
            $bahan = Bahan::find($id);
            
            if (!$bahan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bahan tidak ditemukan'
                ], 404);
            }
            
            $items = $bahan->items;
            
            return response()->json([
                'success' => true,
                'bahan' => $bahan->nama_bahan,
                'items' => $items
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil data item berdasarkan bahan',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}