<form method="POST" action="{{ route('admin.products.destroy', $product) }}"
      onsubmit="return confirm(@js('Delete ' . $product->name . ' permanently? Its variants, prices, stock and stock history are removed from the database. This cannot be undone.'))">
    @csrf @method('DELETE')
    <button class="text-xs text-rose-600 hover:underline">Delete</button>
</form>
