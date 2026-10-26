<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Commande {{ $order->order_number }} confirmée</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f4f5;font-family:Helvetica,Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:8px;overflow:hidden;max-width:600px;width:100%;">

                    {{-- En-tete --}}
                    <tr>
                        <td style="padding:32px 32px 0 32px;">
                            <h1 style="margin:0;font-size:22px;color:#18181b;">
                                Merci {{ $order->customer_name }} !
                            </h1>
                            <p style="margin:8px 0 0 0;font-size:15px;color:#52525b;">
                                Votre paiement est confirmé. Voici le récapitulatif de votre commande.
                            </p>
                        </td>
                    </tr>

                    {{-- Numero de commande --}}
                    <tr>
                        <td style="padding:24px 32px 0 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5;border-radius:6px;">
                                <tr>
                                    <td style="padding:14px 18px;font-size:14px;color="#3f3f46;">
                                        Commande&nbsp;:
                                        <strong style="color:#18181b;">{{ $order->order_number }}</strong>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- QR de retrait, embarque dans le corps --}}
                    @if ($isPickup && $qrInline)
                        <tr>
                            <td align="center" style="padding:28px 32px 0 32px;">
                                <p style="margin:0 0 16px 0;font-size:15px;color:#3f3f46;">
                                    Présentez ce QR Code au stand merch le jour du festival :
                                </p>
                                <img
                                    src="{{ $message->embedData($qrInline, 'qrcode.png', 'image/png') }}"
                                    alt="QR Code de retrait"
                                    width="220"
                                    height="220"
                                    style="display:block;margin:0 auto;border:1px solid #e4e4e7;border-radius:8px;"
                                >
                                <p style="margin:14px 0 0 0;font-size:13px;color="#71717a;">
                                    Le même QR est en pièce jointe de cet e-mail, à conserver.
                                </p>
                            </td>
                        </tr>
                    @endif

                    {{-- Articles --}}
                    <tr>
                        <td style="padding:28px 32px 0 32px;">
                            <h2 style="margin:0 0 12px 0;font-size:16px;color:#18181b;">Articles</h2>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:14px;">
                                @foreach ($order->items as $item)
                                    <tr style="border-bottom:1px solid #e4e4e7;">
                                        <td style="padding:10px 0;color:#3f3f46;">
                                            {{ $item->product_name }}
                                            @if ($item->variant_name)
                                                <span style="color:#71717a;">— {{ $item->variant_name }}</span>
                                            @endif
                                            <br>
                                            <span style="color:#71717a;font-size:13px;">× {{ $item->quantity }}</span>
                                        </td>
                                        <td align="right" style="padding:10px 0;color:#18181b;white-space:nowrap;">
                                            {{ number_format($item->total_price, 0, ',', ' ') }} FCFA
                                        </td>
                                    </tr>
                                @endforeach

                                {{-- Total --}}
                                <tr>
                                    <td align="right" style="padding:14px 0 0 0;font-size:15px;color:#18181b;">
                                        <strong>Total</strong>
                                    </td>
                                    <td align="right" style="padding:14px 0 0 0;font-size:15px;color:#18181b;white-space:nowrap;">
                                        <strong>{{ number_format($order->total, 0, ',', ' ') }} FCFA</strong>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Livraison --}}
                    @if (! $isPickup && $order->shipping_address)
                        <tr>
                            <td style="padding:24px 32px 0 32px;">
                                <h2 style="margin:0 0 8px 0;font-size:16px;color:#18181b;">Livraison</h2>
                                <p style="margin:0;font-size:14px;color:#3f3f46;">
                                    {{ $order->shipping_address }}
                                </p>
                            </td>
                        </tr>
                    @endif

                    {{-- Pied de page --}}
                    <tr>
                        <td style="padding:28px 32px 32px 32px;">
                            <hr style="border:none;border-top:1px solid #e4e4e7;margin:0 0 16px 0;">
                            <p style="margin:0;font-size:13px;color:#71717a;">
                                TDEV Festival — Merch Shop
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
