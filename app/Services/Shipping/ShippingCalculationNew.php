<?php
/**
 * @author TechVillage <support@techvill.org>
 *
 * @contributor Sakawat Hossain <[sakawat.techvill@gmail.com]>
 *
 * @created 01-10-2022
 */

namespace App\Services\Shipping;

use Cart;

class ShippingCalculationNew
{
    /**
     * store zone
     *
     * @var null
     */
    protected $zone = null;

    /**
     * store compare address
     *
     * @var null
     */
    protected $compareAddress = null;

    /**
     * stoe quantity
     *
     * @var null
     */
    protected $quantity = null;

    /**
     * store quantity
     *
     * @var null
     */
    protected $from = null;

    /**
     * store product price
     *
     * @var int
     */
    protected $price = 0;

    public function __construct($zone, $compareAddress, $quantity, $from, $price)
    {
        $this->zone = $zone;
        $this->quantity = $quantity;
        $this->compareAddress = $compareAddress;
        $this->from = $from;
        $this->price = $price;
    }

    /**
     * calculate shipping
     *
     * @return array
     */
    public function calculateShipping()
    {
        $zone = $this->zone;
        $compareAddress = $this->compareAddress;
        $from = $this->from;
        $quantity = $this->quantity;
        $price = $this->price;

        \Log::info('ShippingCalculationNew - Zone type: ' . get_class($zone));
        \Log::info('ShippingCalculationNew - Zone ID: ' . $zone->id);
        
        // Check if zone has ShippingZoneGeolocales relationship
        if (get_class($zone) === 'Modules\\Shipping\\Entities\\ShippingZoneShippingClass') {
            \Log::info('Using zone->shippingZone ShippingZoneGeolocales');
            $flag = $this->checkApplicableAddress($zone->shippingZone, $compareAddress);
        } else {
            \Log::info('Using zone ShippingZoneGeolocales');
            $flag = $this->checkApplicableAddress($zone, $compareAddress);
        }
        
        $methods = [];

        if ($flag == true) {
            \Log::info('Address flag is true, checking methods');
            
            // Get shipping methods
            $shippingMethods = get_class($zone) === 'Modules\\Shipping\\Entities\\ShippingZoneShippingClass' ? 
                $zone->shippingZone->shippingZoneShippingMethods : 
                $zone->ShippingZoneShippingMethod;
                
            \Log::info('Found methods count: ' . $shippingMethods->count());
            \Log::info('Methods data: ' . json_encode($shippingMethods->toArray()));

            foreach ($shippingMethods as $method) {
                $methodCost = 0;
                $zoneCost = 0;
                \Log::info('Processing method: ' . $method->method_title . ', Status: ' . $method->status);
                
                if ($method->status == 1) {
                    if ($method->shipping_method_id == 1) {
                        $allowFreeShipping = false;

                        if ($method->requirements == 'min_amount' && Cart::totalPrice('selected') >= $method->cost) {
                            $allowFreeShipping = $this->checkOrderRule($method->cost, $method->calculation_type);
                        } elseif ($method->requirements == 'coupon' && Cart::checkCouponFreeShipping()) {
                            $allowFreeShipping = true;
                        } elseif ($method->requirements == 'either' && Cart::checkCouponFreeShipping() || $method->requirements == 'either' && Cart::totalPrice('selected') >= $method->cost) {
                            $allowFreeShipping = $this->checkOrderRule($method->cost, $method->calculation_type, 'or');
                        } elseif ($method->requirements == 'both' && Cart::checkCouponFreeShipping() && Cart::totalPrice('selected') >= $method->cost) {
                            $allowFreeShipping = $this->checkOrderRule($method->cost, $method->calculation_type);
                        } elseif ($method->requirements == '') {
                            $allowFreeShipping = true;
                        }

                        if ($allowFreeShipping) {
                            if ($from == 'order') {
                                $methods[$method->method_title] = $methodCost + $zoneCost;
                            } else {

                                if (! empty($method->method_title)) {
                                    $methods[] = [
                                        'shipping_id' => $method->shipping_method_id,
                                        'title' => $method->method_title,
                                        'method_cost' => $methodCost,
                                        'zone_cost' => $zoneCost,
                                        'method_cost_type' => $method->cost_type,
                                        'addMethodZone' => $methodCost + $zoneCost,
                                        'calculation_type' => $method->calculation_type,
                                        'tax_status' => $method->tax_status,
                                    ];
                                }

                            }
                        }

                    } else {
                        if ($method->cost_type == 'cost_per_order') {
                            $methodCost = $method->cost;
                        } elseif ($method->cost_type == 'cost_per_quantity') {
                            $methodCost = $method->cost * $quantity;
                        } elseif ($method->cost_type == 'percent_sub_total_item_price') {
                            $methodCost = ($method->cost * Cart::totalPrice('selected')) / 100;
                        }

                        if ($method->shipping_method_id == 3) {

                            if ($zone->cost_type == 'cost_per_order') {

                                if (! isset($GLOBALS['shipping_slug'])) {
                                    $GLOBALS['shipping_slug'] = [];
                                }

                                $zoneCost = ! in_array($zone->shipping_class_slug, $GLOBALS['shipping_slug']) ? $zone->cost : 0;
                                $GLOBALS['shipping_slug'][] = $zone->shipping_class_slug;
                            } elseif ($zone->cost_type == 'cost_per_quantity') {
                                $zoneCost = $zone->cost * $quantity;
                            } elseif ($zone->cost_type == 'percent_sub_total_item_price') {
                                $zoneCost = ($zone->cost * ($price * $quantity)) / 100;
                            }

                        }
                        
                        \Log::info('Method cost: ' . $methodCost . ', Zone cost: ' . $zoneCost);

                        if ($from == 'order') {
                            ! empty($method->method_title) ? $methods[$method->method_title] = $methodCost + $zoneCost : '';
                        } else {

                            if (! empty($method->method_title)) {
                                $methods[] = [
                                    'shipping_id' => $method->shipping_method_id,
                                    'title' => $method->method_title,
                                    'method_cost' => $methodCost,
                                    'zone_cost' => $zoneCost,
                                    'method_cost_type' => $method->cost_type,
                                    'addMethodZone' => $methodCost + $zoneCost,
                                    'calculation_type' => $method->calculation_type,
                                    'tax_status' => $method->tax_status,
                                ];
                            }

                        }
                    }

                }
            }

        } else {
            \Log::info('Address flag is false, no shipping available');
        }
        
        \Log::info('Final methods: ' . json_encode($methods));
        return $methods;
    }

    /**
     * check whether address applicable or not
     *
     * @return bool
     */
    public function checkApplicableAddress($shippingAddress = null, $compareAddress = null)
    {
        \Log::info('Checking address for zone: ' . $shippingAddress->id);
        \Log::info('Compare address: ' . json_encode($compareAddress));

        foreach ($shippingAddress->ShippingZoneGeolocales as $geolocale) {
            $flag = true;
            
            \Log::info('Checking geolocale: Country=' . $geolocale->country . ', State=' . $geolocale->state . ', City=' . $geolocale->city);

            if ($geolocale->country != '') {
                if (! is_null($compareAddress)) {
                    if (strtolower($geolocale->country) != strtolower($compareAddress->country)) {
                        $flag = false;
                        \Log::info('Country mismatch: ' . $geolocale->country . ' vs ' . $compareAddress->country);
                    } else {
                        \Log::info('Country matched');
                    }
                } else {
                    $flag = false;
                }
            }

            if ($flag && ($geolocale->state) != '') {
                if (! is_null($compareAddress)) {
                    if ($geolocale->state != $compareAddress->state) {
                        $flag = false;
                        \Log::info('State mismatch: ' . $geolocale->state . ' vs ' . $compareAddress->state);
                    } else {
                        \Log::info('State matched');
                    }
                } else {
                    $flag = false;
                }
            }

            if ($flag && $geolocale->city != '') {
                if (! is_null($compareAddress)) {
                    // Normalize city names by removing punctuation and converting to lowercase
                    $normalizedGeoCity = strtolower(preg_replace('/[^a-zA-Z0-9\s]/', '', $geolocale->city));
                    $normalizedCompareCity = strtolower(preg_replace('/[^a-zA-Z0-9\s]/', '', $compareAddress->city));
                    
                    if ($normalizedGeoCity != $normalizedCompareCity) {
                        $flag = false;
                        \Log::info('City mismatch: ' . $geolocale->city . ' vs ' . $compareAddress->city);
                    } else {
                        \Log::info('City matched');
                    }
                } else {
                    $flag = false;
                }
            }

            if ($flag && $geolocale->zip != '' && !is_null($compareAddress->post_code)) {
                if (! is_null($compareAddress)) {
                    if ($geolocale->zip != $compareAddress->post_code) {
                        $flag = false;
                        \Log::info('ZIP mismatch: ' . $geolocale->zip . ' vs ' . $compareAddress->post_code);
                    } else {
                        \Log::info('ZIP matched');
                    }
                } else {
                    $flag = false;
                }
            }

            if ($flag) {
                \Log::info('Address matched for geolocale!');
                return true;
            }
        }
        
        \Log::info('No address match found');
        return false;
    }

    /**
     * check order rule
     *
     * @return bool
     */
    public function checkOrderRule($methodCost = 0, $isRuleChecked = null, $type = null)
    {
        $orderAmount = Cart::totalPrice('selected') - Cart::getCouponData();

        if ($isRuleChecked == 1) {
            return true;
        } elseif ($type == 'or' && Cart::checkCouponFreeShipping()) {
            return true;
        } elseif ($orderAmount >= $methodCost || is_null($methodCost)) {
            return true;
        }

        return false;
    }
}