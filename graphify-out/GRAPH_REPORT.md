# Graph Report - app  (2026-09-20)

## Corpus Check
- 213 files · ~95,075 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 1524 nodes · 4580 edges · 104 communities (37 shown, 67 thin omitted)
- Extraction: 99% EXTRACTED · 1% INFERRED · 0% AMBIGUOUS · INFERRED: 58 edges (avg confidence: 0.85)
- Token cost: 0 input · 0 output

## Community Hubs (Navigation)
- Admin Profile & Staff Accounts
- Logistics Application Emails
- Courier Resignation Requests
- Seller Logistics & Company
- Seller Messaging
- Logistics Company & Pickup Courier
- Parcel Assignment (Logistics)
- Logistics Messaging
- Seller Delivery Reports
- Admin & Logistics Controllers (Mixed)
- Driver Delivery Workflow
- Account Registration & Profiles
- Admin/Logistics Request Validation
- Message & Conversation Models
- Courier Console Commands
- Courier Application & Attachments
- Driver Messaging
- Regional/Provincial Assignment Models
- Admin Support Request Validation
- Conversation Model
- Admin Notifications & Account Status
- Order Model
- Auth Middleware
- Admin Customer Service Controller
- Buyer Messaging
- Buyer Returns & Order Items
- Model Factories & UUID Keys
- Courier/Logistics Profile Controllers
- Barangay Rider Assignment
- Admin & Buyer Account Controllers
- Admin Commission & Reports
- Support Ticket Policy
- Buyer Payment & Order Item Models
- Auth & Misc Controllers
- Checkout & Direct Conversation
- Customer Service Support Tickets
- Address & Complaint Models
- Profile Detail Relationships
- Product Catalog Controller
- Seller Inventory & Product
- User & Complaint Models
- Buyer Message Requests
- Category Config
- Inventory Movement Service
- Seller Compliance & Categories
- Seller Feedback (Reviews)
- Order Status History & Checkout
- Conversation Policy
- Provincial Rider Assignment
- Regional Rider Assignment
- Seller Order Management
- Product Variant & Inventory
- Parcel Tracking Simulation
- Buyer Order & Cancellation
- Seller Delivery Filters
- Message Attachments
- Review Model
- Admin Complaint Controller
- Buyer Account Controller
- Order Tracking Service
- Seller Product Requests
- Buyer/Seller Stock Requests
- Seller Product Service
- Buyer Address Controller
- Buyer Payment Method Controller
- Buyer Wishlist
- App Service Provider
- Vehicle Category & Auto-Assign
- Delivery Conversation Service
- Buyer Review Controller
- Seller Notifications Controller
- Review Report Request
- Seller Notification Model
- Document Review Request
- Admin User Account Controller
- Deactivate Admin Account Request
- Update Admin Profile Request
- Deactivate Buyer Account Request
- Update Buyer Profile Request
- Deactivate Driver Account Request
- Update Driver Profile Request
- Respond to Review Request
- Transfer Trigger Service
- Assign Support Ticket Request
- Store Staff Account Request
- Support Ticket Internal Note
- Store Address Request
- Store Payment Method Request
- Update Address Request
- Update Payment Method Request
- Reply Support Ticket Request
- Driver Send Message Request
- Start Delivery Conversation Request
- Update Driver Conversation Status
- Assign Parcel Request
- Assign Transfer Courier Request
- Bulk Barangay Assignment Request
- Logistics Send Message Request
- Update Barangay Assignment Request
- Update Logistics Conversation Status
- Send Seller Message Request
- Start Logistics Conversation Request
- Update Order Status Request

## God Nodes (most connected - your core abstractions)
1. `Profile` - 144 edges
2. `Controller` - 104 edges
3. `Order` - 95 edges
4. `Conversation` - 73 edges
5. `ParcelAssignment` - 72 edges
6. `LogisticsCompany` - 71 edges
7. `Product` - 61 edges
8. `CourierApplication` - 53 edges
9. `HasUuidPrimaryKey` - 50 edges
10. `SupportTicket` - 47 edges

## Surprising Connections (you probably didn't know these)
- `CustomerServiceController` --references--> `SupportTicketPolicy`  [EXTRACTED]
  Http/Controllers/Admin/CustomerServiceController.php → Policies/SupportTicketPolicy.php
- `CourierApplicationController` --references--> `SupabaseStorageService`  [EXTRACTED]
  Http/Controllers/Api/Courier/CourierApplicationController.php → Services/SupabaseStorageService.php
- `LogisticsApplicationController` --references--> `SupabaseStorageService`  [EXTRACTED]
  Http/Controllers/Api/Logistics/LogisticsApplicationController.php → Services/SupabaseStorageService.php
- `ParcelAssignmentController` --references--> `ParcelAutoAssignService`  [EXTRACTED]
  Http/Controllers/Api/Logistics/ParcelAssignmentController.php → Services/ParcelAutoAssignService.php
- `ParcelAssignmentController` --references--> `ParcelIntakeService`  [EXTRACTED]
  Http/Controllers/Api/Logistics/ParcelAssignmentController.php → Services/ParcelIntakeService.php

## Import Cycles
- None detected.

## Communities (104 total, 67 thin omitted)

### Community 0 - "Admin Profile & Staff Accounts"
Cohesion: 0.06
Nodes (8): AdminProfileController, StaffAccountController, AuthController, BuyerProfileController, DriverProfileController, PasswordResetController, Illuminate\Http\Client\Response, PasswordResetCode

### Community 1 - "Logistics Application Emails"
Cohesion: 0.07
Nodes (21): Carbon\Carbon, LogisticsNotificationController, Illuminate\Bus\Queueable, Illuminate\Contracts\Queue\ShouldQueue, Illuminate\Mail\Mailable, Illuminate\Mail\Mailables\Attachment, Illuminate\Mail\Mailables\Content, Illuminate\Mail\Mailables\Envelope (+13 more)

### Community 2 - "Courier Resignation Requests"
Cohesion: 0.10
Nodes (5): ResignationRequestController, ResignationRequestController, SellerProductController, ResignationRequest, SupabaseStorageService

### Community 3 - "Seller Logistics & Company"
Cohesion: 0.11
Nodes (4): SellerLogisticsController, LogisticsCompany, ParcelAutoAssignService, ParcelIntakeService

### Community 4 - "Seller Messaging"
Cohesion: 0.11
Nodes (3): MessageController, ReportBuyerRequest, UpdateConversationStatusRequest

### Community 5 - "Logistics Company & Pickup Courier"
Cohesion: 0.12
Nodes (11): LogisticsCompanyController, PickupCourierController, HandleInertiaRequests, LogisticsCompanyResource, BarangayAssignmentResource, LogisticsApplicationResource, Illuminate\Http\Request, Illuminate\Http\Resources\Json\AnonymousResourceCollection (+3 more)

### Community 6 - "Parcel Assignment (Logistics)"
Cohesion: 0.15
Nodes (4): ParcelAssignmentController, ParcelAssignmentResource, ParcelTransferRequestResource, ParcelTransferRequest

### Community 7 - "Logistics Messaging"
Cohesion: 0.13
Nodes (4): MessageController, Illuminate\Database\Eloquent\Collection, Illuminate\Http\UploadedFile, MessageAttachmentService

### Community 8 - "Seller Delivery Reports"
Cohesion: 0.18
Nodes (3): Carbon\CarbonImmutable, SellerReportController, Illuminate\Http\Response

### Community 9 - "Admin & Logistics Controllers (Mixed)"
Cohesion: 0.12
Nodes (8): ChecksRiderCoverage, Illuminate\Database\Eloquent\Builder, Illuminate\Database\Query\JoinClause, Illuminate\Support\Facades\Cache, Illuminate\Validation\ValidationException, ConversationParticipant, ServiceAreaProvisioner, ShipmentConversationService

### Community 10 - "Driver Delivery Workflow"
Cohesion: 0.13
Nodes (3): DriverDeliveryController, ParcelAssignment, self

### Community 11 - "Account Registration & Profiles"
Cohesion: 0.13
Nodes (4): AccountRegistrationController, Illuminate\Database\Eloquent\Relations\HasMany, Illuminate\Notifications\Notifiable, Profile

### Community 12 - "Admin/Logistics Request Validation"
Cohesion: 0.09
Nodes (8): RejectRegistrationRequest, ResolveSupportTicketRequest, AssignTransferRequest, ReceiveParcelRequest, StoreBarangayAssignmentRequest, StoreCourierApplicationRequest, Illuminate\Foundation\Http\FormRequest, Illuminate\Validation\Rules\File

### Community 13 - "Message & Conversation Models"
Cohesion: 0.11
Nodes (6): Illuminate\Database\Eloquent\Relations\BelongsTo, Illuminate\Database\Eloquent\SoftDeletes, Message, OrderReturnRequest, ParcelScanEvent, SellerComplianceAction

### Community 14 - "Courier Console Commands"
Cohesion: 0.12
Nodes (10): BackfillOrderShippingArea, DeleteDummyCouriers, MakeDummyCouriers, PruneStagedMessageAttachments, MessageAttachmentController, Illuminate\Console\Attributes\Description, Illuminate\Console\Attributes\Signature, Illuminate\Console\Command (+2 more)

### Community 15 - "Courier Application & Attachments"
Cohesion: 0.16
Nodes (4): CourierApplicationController, PsgcProxyController, UploadMessageAttachmentRequest, Illuminate\Http\JsonResponse

### Community 17 - "Regional/Provincial Assignment Models"
Cohesion: 0.10
Nodes (6): Database\Factories\LogisticsProvincialAssignmentFactory, Database\Factories\LogisticsRegionalAssignmentFactory, Illuminate\Database\Eloquent\Relations\BelongsToMany, LogisticsProvincialAssignment, LogisticsRegionalAssignment, ProductOptionValue

### Community 18 - "Admin Support Request Validation"
Cohesion: 0.10
Nodes (6): ReplySupportTicketRequest, UpdateAccountStatusRequest, UpdateSupportTicketStatusRequest, StoreSupportTicketRequest, Illuminate\Contracts\Validation\ValidationRule, Illuminate\Validation\Rule

### Community 20 - "Admin Notifications & Account Status"
Cohesion: 0.16
Nodes (8): Illuminate\Support\Facades\DB, Illuminate\Support\Facades\Mail, AccountStatusChanged, Address, Document, BelongsTo, BelongsTo, StatusAuditLog

### Community 22 - "Auth Middleware"
Cohesion: 0.17
Nodes (8): Closure, AuthenticateSupabaseUser, EnsureUserIsAdmin, EnsureUserIsBuyer, EnsureUserIsDriver, EnsureUserIsLogistics, EnsureUserIsSeller, Symfony\Component\HttpFoundation\Response

### Community 23 - "Admin Customer Service Controller"
Cohesion: 0.17
Nodes (4): CustomerServiceController, Illuminate\Notifications\Notification, SupportTicket, SupportTicketUpdated

### Community 25 - "Buyer Returns & Order Items"
Cohesion: 0.16
Nodes (6): Carbon\CarbonInterface, Carbon\Constants\UnitValue, ReturnController, Illuminate\Support\Facades\Validator, OrderItem, SellerReportService

### Community 26 - "Model Factories & UUID Keys"
Cohesion: 0.12
Nodes (8): Database\Factories\ConversationParticipantFactory, Database\Factories\LogisticsBarangayAssignmentFactory, Database\Factories\ParcelAssignmentFactory, Database\Factories\SupportTicketFactory, Database\Factories\SupportTicketInternalNoteFactory, Illuminate\Database\Eloquent\Factories\HasFactory, Illuminate\Support\Str, SupportTicketInternalNote

### Community 27 - "Courier/Logistics Profile Controllers"
Cohesion: 0.17
Nodes (3): CourierProfileController, LogisticsApplicationController, CourierApplication

### Community 29 - "Admin & Buyer Account Controllers"
Cohesion: 0.12
Nodes (4): AdminNotificationController, DashboardController, Controller, ParcelLocationController

### Community 30 - "Admin Commission & Reports"
Cohesion: 0.18
Nodes (5): CommissionController, ReportController, GenerateReportRequest, CommissionCalculator, Symfony\Component\HttpFoundation\StreamedResponse

### Community 32 - "Buyer Payment & Order Item Models"
Cohesion: 0.13
Nodes (5): BuyerPaymentMethod, HasUuidPrimaryKey, ParcelLocation, ProductOption, ReviewReport

### Community 33 - "Auth & Misc Controllers"
Cohesion: 0.22
Nodes (7): Illuminate\Http\Client\ConnectionException, Illuminate\Http\Client\PendingRequest, Illuminate\Support\Facades\Http, Illuminate\Support\Facades\Log, Laravel\Socialite\Facades\Socialite, RuntimeException, Throwable

### Community 34 - "Checkout & Direct Conversation"
Cohesion: 0.18
Nodes (4): CheckoutController, CheckoutRequest, DirectConversationService, SellerNotifier

### Community 36 - "Address & Complaint Models"
Cohesion: 0.14
Nodes (5): Illuminate\Database\Eloquent\Model, BuyerAddress, ComplaintUpdate, LogisticsAdminDetail, SellerDetail

### Community 37 - "Profile Detail Relationships"
Cohesion: 0.12
Nodes (3): Illuminate\Database\Eloquent\Relations\HasOne, CourierDetail, DriverDetail

### Community 40 - "User & Complaint Models"
Cohesion: 0.13
Nodes (7): Database\Factories\UserFactory, Illuminate\Database\Eloquent\Attributes\Fillable, Illuminate\Database\Eloquent\Attributes\Hidden, Illuminate\Foundation\Auth\User, Illuminate\Support\Carbon, Complaint, User

### Community 41 - "Buyer Message Requests"
Cohesion: 0.12
Nodes (4): SendMessageRequest, StartConversationRequest, StartCourierConversationRequest, UpdateConversationStatusRequest

### Community 44 - "Seller Compliance & Categories"
Cohesion: 0.18
Nodes (3): SellerComplianceController, StoreSellerComplianceActionRequest, CategoryMatcher

### Community 46 - "Order Status History & Checkout"
Cohesion: 0.23
Nodes (4): Illuminate\Support\Collection, Illuminate\Support\Facades\Schema, OrderStatusHistory, CheckoutService

### Community 58 - "Buyer Account Controller"
Cohesion: 0.31
Nodes (3): AccountController, UpdateProfileRequest, Illuminate\Database\QueryException

### Community 66 - "App Service Provider"
Cohesion: 0.32
Nodes (4): Illuminate\Support\Facades\Date, Illuminate\Support\ServiceProvider, Illuminate\Validation\Rules\Password, AppServiceProvider

## Knowledge Gaps
- **67 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `Profile` connect `Account Registration & Profiles` to `Admin Profile & Staff Accounts`, `Courier Resignation Requests`, `Seller Logistics & Company`, `Seller Messaging`, `Logistics Messaging`, `Admin & Logistics Controllers (Mixed)`, `Driver Delivery Workflow`, `Courier Console Commands`, `Courier Application & Attachments`, `Regional/Provincial Assignment Models`, `Admin Notifications & Account Status`, `Auth Middleware`, `Admin Customer Service Controller`, `Buyer Messaging`, `Courier/Logistics Profile Controllers`, `Barangay Rider Assignment`, `Admin & Buyer Account Controllers`, `Support Ticket Policy`, `Auth & Misc Controllers`, `Checkout & Direct Conversation`, `Customer Service Support Tickets`, `Address & Complaint Models`, `Profile Detail Relationships`, `Buyer Message Requests`, `Inventory Movement Service`, `Order Status History & Checkout`, `Conversation Policy`, `Provincial Rider Assignment`, `Regional Rider Assignment`, `Product Variant & Inventory`, `Buyer Order & Cancellation`, `Admin Complaint Controller`, `Seller Product Service`, `Vehicle Category & Auto-Assign`, `Delivery Conversation Service`, `Admin User Account Controller`?**
  _High betweenness centrality (0.122) - this node is a cross-community bridge._
- **Why does `Controller` connect `Admin & Buyer Account Controllers` to `Admin Profile & Staff Accounts`, `Logistics Application Emails`, `Courier Resignation Requests`, `Seller Logistics & Company`, `Seller Messaging`, `Logistics Company & Pickup Courier`, `Parcel Assignment (Logistics)`, `Logistics Messaging`, `Seller Delivery Reports`, `Admin & Logistics Controllers (Mixed)`, `Driver Delivery Workflow`, `Account Registration & Profiles`, `Courier Console Commands`, `Courier Application & Attachments`, `Driver Messaging`, `Admin Notifications & Account Status`, `Admin Customer Service Controller`, `Buyer Messaging`, `Buyer Returns & Order Items`, `Courier/Logistics Profile Controllers`, `Barangay Rider Assignment`, `Admin Commission & Reports`, `Auth & Misc Controllers`, `Checkout & Direct Conversation`, `Customer Service Support Tickets`, `Product Catalog Controller`, `Seller Inventory & Product`, `Buyer Message Requests`, `Category Config`, `Seller Compliance & Categories`, `Seller Feedback (Reviews)`, `Provincial Rider Assignment`, `Regional Rider Assignment`, `Seller Order Management`, `Buyer Order & Cancellation`, `Seller Delivery Filters`, `Admin Complaint Controller`, `Buyer Account Controller`, `Seller Product Requests`, `Buyer Address Controller`, `Buyer Payment Method Controller`, `Buyer Wishlist`, `Buyer Review Controller`, `Seller Notifications Controller`, `Admin User Account Controller`?**
  _High betweenness centrality (0.108) - this node is a cross-community bridge._
- **Why does `Order` connect `Order Model` to `Seller Logistics & Company`, `Seller Messaging`, `Parcel Assignment (Logistics)`, `Seller Delivery Reports`, `Admin & Logistics Controllers (Mixed)`, `Driver Delivery Workflow`, `Courier Console Commands`, `Buyer Messaging`, `Buyer Returns & Order Items`, `Model Factories & UUID Keys`, `Barangay Rider Assignment`, `Admin & Buyer Account Controllers`, `Admin Commission & Reports`, `Buyer Payment & Order Item Models`, `Checkout & Direct Conversation`, `Customer Service Support Tickets`, `Address & Complaint Models`, `Buyer Message Requests`, `Inventory Movement Service`, `Order Status History & Checkout`, `Seller Order Management`, `Product Variant & Inventory`, `Parcel Tracking Simulation`, `Buyer Order & Cancellation`, `Seller Delivery Filters`, `Order Tracking Service`, `Vehicle Category & Auto-Assign`, `Delivery Conversation Service`, `Seller Notification Model`, `Transfer Trigger Service`, `Update Order Status Request`?**
  _High betweenness centrality (0.086) - this node is a cross-community bridge._
- **Should `Admin Profile & Staff Accounts` be split into smaller, more focused modules?**
  _Cohesion score 0.06277665995975855 - nodes in this community are weakly interconnected._
- **Should `Logistics Application Emails` be split into smaller, more focused modules?**
  _Cohesion score 0.06538461538461539 - nodes in this community are weakly interconnected._
- **Should `Courier Resignation Requests` be split into smaller, more focused modules?**
  _Cohesion score 0.1 - nodes in this community are weakly interconnected._
- **Should `Seller Logistics & Company` be split into smaller, more focused modules?**
  _Cohesion score 0.11261261261261261 - nodes in this community are weakly interconnected._